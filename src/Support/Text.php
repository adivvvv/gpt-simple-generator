<?php
// src/Support/Text.php
declare(strict_types=1);

namespace App\Support;

final class Text
{
    public static function sentenceCountsPerParagraph(string $markdown): array
    {
        $paras = preg_split('/\n{2,}/', trim(str_replace("\r", '', $markdown))) ?: [];
        $counts = [];
        foreach ($paras as $p) {
            $p = trim($p);
            if ($p === '') { $counts[] = 0; continue; }
            // crude sentence split on . ? !
            $n = preg_split('/(?<=[\.!\?])\s+/', $p) ?: [];
            // filter out very short fragments
            $n = array_values(array_filter($n, fn($s) => mb_strlen(trim($s)) > 20));
            $counts[] = count($n);
        }
        return $counts;
    }

    public static function introContainsBanned(string $markdown, array $banPhrases): bool
    {
        $firstPara = trim((string)(preg_split('/\n{2,}/', $markdown)[0] ?? ''));
        $low = mb_strtolower($firstPara);
        foreach ($banPhrases as $b) {
            if ($b !== '' && str_contains($low, mb_strtolower($b))) {
                return true;
            }
        }
        return false;
    }

    public static function hashSalt(array $payload): string
    {
        return hash('xxh3', json_encode([$payload['keywords'] ?? [], microtime(true)], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Enforce inline PMID policy in body markdown.
     *  - policy "none": remove ALL [PMID:xxxxx]
     *  - policy "limited": keep up to $max from $allowedPmids (first seen), drop the rest
     */
    public static function limitInlinePmids(string $body, string $policy, int $max, array $allowedPmids): string
    {
        if ($policy === 'none') {
            return preg_replace('/\s*\[PMID:\s*\d{5,9}\]/i', '', $body) ?? $body;
        }

        if ($policy !== 'limited') return $body;

        $kept = 0;
        $seen = [];
        return preg_replace_callback('/\s*\[PMID:\s*(\d{5,9})\]/i', function($m) use (&$kept, $max, &$seen, $allowedPmids) {
            $pmid = $m[1];
            if ($kept >= $max) return '';                 // over cap
            if (!in_array($pmid, $allowedPmids, true)) return ''; // not allowed
            if (isset($seen[$pmid])) return '';           // duplicate
            $seen[$pmid] = true;
            $kept++;
            return " [PMID:{$pmid}]";
        }, $body) ?? $body;
    }
}