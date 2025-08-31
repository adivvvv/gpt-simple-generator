<?php
declare(strict_types=1);

namespace App\Services;

final class PubMedService
{
    private string $base = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/';
    private string $cacheDir;

    public function __construct()
    {
        $this->cacheDir = __DIR__ . '/../../' . (getenv('CACHE_DIR') ?: 'storage/cache');
    }

    /**
     * Build context pack from PubMed: [{pmid,title,url,excerpt}]
     * - Combines camel milk synonyms with user keywords.
     * - Caches for 24h.
     */
    public function context(string $lang, array $keywords, int $retmax = 12): array
    {
        $term = $this->buildQuery($keywords);
        $cacheKey = 'pm_' . md5($term . '|' . $retmax);
        $cacheFile = $this->cacheDir . '/' . $cacheKey . '.json';

        if (is_file($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
            $data = json_decode((string)file_get_contents($cacheFile), true);
            return is_array($data) ? $data : [];
        }

        $ids = $this->esearch($term, $retmax);
        if (!$ids) return [];

        $articles = $this->efetch($ids);
        $out = [];
        foreach ($articles as $a) {
            $pmid  = (string)($a['pmid'] ?? '');
            $title = trim((string)($a['title'] ?? ''));
            $ab    = trim((string)($a['abstract'] ?? ''));
            if (!$pmid || !$title) continue;

            $out[] = [
                'pmid' => $pmid,
                'title' => $title,
                'url' => "https://pubmed.ncbi.nlm.nih.gov/{$pmid}/",
                'excerpt' => $this->firstSentences($ab, 2),
            ];
        }

        @file_put_contents($cacheFile, json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $out;
    }

    private function buildQuery(array $keywords): string
    {
        $camel = '(' . implode(' OR ', [
            '"camel milk"[Title/Abstract]',
            '"camel\'s milk"[Title/Abstract]',
            '"camels milk"[Title/Abstract]',
            '"camel milk powder"[Title/Abstract]',
            '"Camelus dromedarius"[Title/Abstract]',
            '"camel milk product"[Title/Abstract]'
        ]) . ')';

        $kw = array_map(static fn($k) => '"' . trim((string)$k) . '"[Title/Abstract]', $keywords);
        $kwTerm = $kw ? '(' . implode(' OR ', $kw) . ')' : '';

        return $kwTerm ? "{$camel} AND {$kwTerm}" : $camel;
    }

    private function esearch(string $term, int $retmax): array
    {
        $params = [
            'db' => 'pubmed',
            'retmode' => 'json',
            'retmax' => $retmax,
            'term' => $term,
            'tool' => getenv('PUBMED_TOOL') ?: 'camelway-generator',
            'email' => getenv('PUBMED_EMAIL') ?: '',
        ];
        if ($k = getenv('PUBMED_API_KEY')) $params['api_key'] = $k;

        $url = $this->base . 'esearch.fcgi?' . http_build_query($params);
        $json = $this->httpGet($url);
        $data = json_decode($json, true);
        return $data['esearchresult']['idlist'] ?? [];
    }

    private function efetch(array $ids): array
    {
        $params = [
            'db' => 'pubmed',
            'retmode' => 'xml',
            'id' => implode(',', $ids),
            'tool' => getenv('PUBMED_TOOL') ?: 'camelway-generator',
            'email' => getenv('PUBMED_EMAIL') ?: '',
        ];
        if ($k = getenv('PUBMED_API_KEY')) $params['api_key'] = $k;

        $url = $this->base . 'efetch.fcgi?' . http_build_query($params);
        $xml = $this->httpGet($url);
        $sx  = @simplexml_load_string($xml);
        if (!$sx) return [];

        $ns = $sx->getNamespaces(true);
        $arts = [];
        foreach ($sx->PubmedArticle as $pa) {
            $pmid = (string)$pa->MedlineCitation->PMID;
            $article = $pa->MedlineCitation->Article;
            $title = trim((string)$article->ArticleTitle);
            $abstract = '';
            if (isset($article->Abstract->AbstractText)) {
                foreach ($article->Abstract->AbstractText as $x) {
                    $abstract .= trim((string)$x) . " ";
                }
            }
            $arts[] = ['pmid'=>$pmid, 'title'=>$title, 'abstract'=>trim($abstract)];
        }
        return $arts;
    }

    private function httpGet(string $url): string
    {
        // Very lightweight GET with retry/backoff
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: CamelWayGenerator/1.0\r\n"]]);
        $tries = 0;
        while ($tries < 3) {
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp !== false) return $resp;
            usleep(400000); // 0.4s backoff (stay under E-utilities rps)
            $tries++;
        }
        return '';
    }

    private function firstSentences(string $text, int $n): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        $parts = preg_split('/(?<=[.!?])\s+/', $text);
        return implode(' ', array_slice($parts ?: [], 0, $n));
    }
}
