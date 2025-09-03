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
        $article = $this->postProcess($article, $pmidPolicy, (int)$payload['maxInlinePmids'], $refs);

        // Validate intro uniqueness and paragraph sentence counts; one retry if needed
        if ($this->needsRetry($article, $banPhrases, $minSp)) {
            Logger::info('Retrying generation with alternate lead and stronger de-boilerplate');
            $payload['leadStyle'] = $leadOptions[random_int(0, count($leadOptions)-1)];
            $payload['temperature'] = 0.55; // a little more diversity
            $payload['banPhrases'][] = substr(Text::hashSalt($payload), 0, 12); // invisible nudge token
            $article = $this->openai->generateArticle($payload, $refs, $schema);
            $article = $this->postProcess($article, $pmidPolicy, (int)$payload['maxInlinePmids'], $refs);
        }

        return $article;
    }

    private function needsRetry(array $article, array $banPhrases, int $minSp): bool
    {
        $body = (string)($article['body_markdown'] ?? '');
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
}