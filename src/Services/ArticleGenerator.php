<?php
// src/Services/ArticleGenerator.php
declare(strict_types=1);

namespace App\Services;

use App\Support\Env;
use App\Support\Logger;
use App\Support\Text;

final class ArticleGenerator
{
    public function __construct(private OpenAIService $openai) {}

    public function generate(array $payload, array $refs): array
    {
        $schema = json_decode(file_get_contents(__DIR__ . '/../../schema/article.schema.json'), true);
        if (!is_array($schema)) {
            throw new \RuntimeException('article.schema.json not found or invalid');
        }

        // Controls
        $minSp = (int)($payload['minSentencesPerParagraph'] ?? (int)(Env::get('CONTENT_MIN_SENTENCES', '4')));
        $payload['minSentencesPerParagraph'] = $minSp;

        $leadOptions = ['question','surprising-stat','myth-busting','historical-note','case-context','analogy'];
        $payload['leadStyle'] = $payload['leadStyle'] ?? $leadOptions[random_int(0, count($leadOptions)-1)];

        // PMID policy: auto => 50% none, 50% limited(<=3)
        $pmidPolicy = (string)($payload['pmidPolicy'] ?? 'auto');
        if ($pmidPolicy === 'auto') {
            $pmidPolicy = (random_int(0, 1) === 0) ? 'none' : 'limited';
        }
        $payload['pmidMode'] = $pmidPolicy;
        $payload['maxInlinePmids'] = (int)($payload['maxInlinePmids'] ?? 3);

        // Ban common boilerplate intros
        $banPhrases = [
            'Camel milk has garnered increasing attention',
            'Traditionally consumed in various cultures',
            'rich in proteins, vitamins, and minerals, making it a valuable dietary component',
            'This article explores its nutritional profile, potential health benefits',
        ];
        $payload['banPhrases'] = $banPhrases;

        // First attempt
        $article = $this->openai->generateArticle($payload, $refs, $schema);
        $article = $this->coerceShape($article, (string)$payload['subject'], $refs);

        // Enforce inline PMID rules after coercion
        $article = $this->postProcess($article, $pmidPolicy, (int)$payload['maxInlinePmids'], $refs);

        // If shape/content still looks wrong (missing fields, empty body, banned intro), do one retry
        if ($this->needsRetry($article, $banPhrases, $minSp)) {
            Logger::info('Retrying generation with alternate lead and stronger schema emphasis');
            $payload['leadStyle'] = $leadOptions[random_int(0, count($leadOptions)-1)];
            $payload['temperature'] = 0.55; // a little more diversity
            $payload['banPhrases'][] = substr(Text::hashSalt($payload), 0, 12); // invisible nudge
            $article = $this->openai->generateArticle($payload, $refs, $schema);
            $article = $this->coerceShape($article, (string)$payload['subject'], $refs);
            $article = $this->postProcess($article, $pmidPolicy, (int)$payload['maxInlinePmids'], $refs);
        }

        return $article;
    }

    private function needsRetry(array $article, array $banPhrases, int $minSp): bool
    {
        // Required keys check
        foreach (['title','slug','summary','body_markdown','faq','references','tags','subject'] as $k) {
            if (!array_key_exists($k, $article)) return true;
        }

        $body = (string)($article['body_markdown'] ?? '');
        if (trim($body) === '') return true;

        if (Text::introContainsBanned($body, $banPhrases)) {
            return true;
        }
        $counts = Text::sentenceCountsPerParagraph($body);
        foreach ($counts as $c) {
            if ($c < $minSp) return true;
        }
        return false;
    }

    private function postProcess(array $article, string $pmidPolicy, int $maxInline, array $refs): array
    {
        $allowed = [];
        foreach ($refs as $r) {
            $p = (string)($r['pmid'] ?? '');
            if ($p !== '') $allowed[] = $p;
        }
        $allowed = array_values(array_unique($allowed));

        $article['body_markdown'] = Text::limitInlinePmids(
            (string)($article['body_markdown'] ?? ''),
            $pmidPolicy,
            $maxInline,
            $allowed
        );

        return $article;
    }

    /** Coerce odd model outputs into our schema (also inject subject). */
    private function coerceShape(array $a, string $subject, array $refs): array
    {
        $a['subject'] = $subject;

        // body_markdown fallback from 'article'
        if (!isset($a['body_markdown']) || trim((string)$a['body_markdown']) === '') {
            if (isset($a['article']) && is_string($a['article'])) {
                $a['body_markdown'] = (string)$a['article'];
            }
        }

        // faq fallback from 'faqs'
        if (!isset($a['faq']) && isset($a['faqs']) && is_array($a['faqs'])) {
            $faq = [];
            foreach ($a['faqs'] as $it) {
                $q = (string)($it['question'] ?? ($it['q'] ?? ''));
                $an = (string)($it['answer'] ?? ($it['a'] ?? ''));
                if ($q !== '' && $an !== '') $faq[] = ['q'=>$q, 'a'=>$an];
            }
            if ($faq) $a['faq'] = $faq;
        }

        // title/slug/summary defaults
        if (!isset($a['title']) || trim((string)$a['title']) === '') {
            $a['title'] = $subject;
        }
        if (!isset($a['slug']) || trim((string)$a['slug']) === '') {
            $a['slug'] = $this->slugify((string)$a['title']);
        }
        if (!isset($a['summary']) || trim((string)$a['summary']) === '') {
            $a['summary'] = $this->summarize((string)($a['body_markdown'] ?? $subject), 170);
        }

        // tags default
        if (!isset($a['tags']) || !is_array($a['tags']) || count($a['tags']) === 0) {
            $a['tags'] = array_values(array_unique(array_filter([
                'camel milk',
                $this->tagFromSubject($subject)
            ])));
        }

        // references default from PubMed context if empty
        if (!isset($a['references']) || !is_array($a['references']) || count($a['references']) === 0) {
            $refsMap = [];
            foreach ($refs as $r) {
                $pmid = (string)($r['pmid'] ?? '');
                if ($pmid === '') continue;
                $refsMap[] = [
                    'title' => (string)($r['title'] ?? ''),
                    'pmid'  => $pmid,
                    'url'   => (string)($r['url'] ?? ('https://pubmed.ncbi.nlm.nih.gov/'.$pmid.'/'))
                ];
            }
            $a['references'] = array_slice($refsMap, 0, 8);
        }

        return $a;
    }

    private function slugify(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^\p{L}\p{Nd}]+/u', '-', $s) ?? '';
        $s = trim($s, '-');
        return $s !== '' ? $s : 'camel-milk';
    }

    private function summarize(string $text, int $max = 170): string
    {
        $t = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (mb_strlen($t) <= $max) return $t;
        return rtrim(mb_substr($t, 0, $max - 1)) . '…';
    }

    private function tagFromSubject(string $subject): string
    {
        // simple tag derivation: take a key noun-ish token
        $t = trim($subject);
        if ($t === '') return 'topic';
        $t = preg_replace('/[^A-Za-z0-9 ]+/', '', $t) ?? 'topic';
        $parts = preg_split('/\s+/', $t) ?: [];
        return strtolower(implode('-', array_slice($parts, 0, 3)));
    }
}