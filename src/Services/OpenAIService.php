<?php
// src/Services/OpenAIService.php
declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use App\Support\Env;
use App\Support\Logger;
use Psr\Http\Message\ResponseInterface;

final class OpenAIService
{
    private Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http ?: new Client([
            'base_uri'        => 'https://api.openai.com/v1/',
            'timeout'         => (float)(Env::get('OPENAI_TIMEOUT', '120')),
            'connect_timeout' => (float)(Env::get('OPENAI_CONNECT_TIMEOUT', '10')),
        ]);
    }

    /** Generate a full article as strict JSON per article.schema.json */
    public function generateArticle(array $payload, array $refs, array $schema): array
    {
        $lang      = (string)$payload['lang'];
        $lead      = (string)($payload['leadStyle'] ?? 'surprising-stat');
        $minSp     = (int)($payload['minSentencesPerParagraph'] ?? 4);
        $pmidMode  = (string)($payload['pmidMode'] ?? 'limited'); // none|limited
        $maxInline = (int)($payload['maxInlinePmids'] ?? 3);
        $banPhrases= (array)($payload['banPhrases'] ?? []);

        $allowedPmids = [];
        foreach ($refs as $r) {
            $p = (string)($r['pmid'] ?? '');
            if ($p !== '') $allowedPmids[] = $p;
        }
        $allowedPmids = array_values(array_unique($allowedPmids));

        $banListText = $banPhrases ? ("Avoid these phrases verbatim or near-duplicate paraphrases: • " . implode(" • ", $banPhrases) . ".") : '';
        $pmidPolicyText = $pmidMode === 'none'
            ? "Inline citations policy: include ZERO inline PMIDs anywhere in the body or FAQ."
            : "Inline citations policy: include AT MOST {$maxInline} total inline PMIDs in the entire article (not per paragraph). Never repeat the same PMID, do not add PMIDs in FAQ. Use only from this allowed set: [" . implode(',', $allowedPmids) . "].";

        $system = implode("\n", [
            "You are a careful scientific editor writing in {$lang}.",
            "Goals:",
            "- Start with a UNIQUE, non-generic introduction using the lead style: {$lead}.",
            "- Structure: target {$payload['paragraphs']} paragraphs; EACH paragraph must have at least {$minSp} sentences.",
            "- Summarize evidence neutrally; no medical advice; EU-compliant tone.",
            "- FAQs: include {$payload['faqCount']} Q/A (plain text).",
            "- Citations: {$pmidPolicyText}",
            $banListText,
            "Constraints:",
            "- Text-only. No images, no tables.",
            "- If you include PMIDs inline, cite like [PMID:12345678].",
        ]);

        $userJson = json_encode([
            'task' => 'write_article',
            'language' => $lang,
            'keywords' => $payload['keywords'],
            'styleFlags' => $payload['styleFlags'],
            'specialRequirements' => $payload['specialRequirements'] ?? '',
            'references' => $refs,
            'controls' => [
                'leadStyle' => $lead,
                'minSentencesPerParagraph' => $minSp,
                'pmidMode' => $pmidMode,
                'maxInlinePmids' => $maxInline,
                'allowedPmids' => $allowedPmids,
                'banPhrases' => $banPhrases
            ]
        ], JSON_UNESCAPED_UNICODE);

        $inputBlocks = [
            ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $system]]],
            ['role' => 'user',   'content' => [['type' => 'input_text', 'text' => "USER_PAYLOAD_JSON:\n".$userJson]]],
        ];

        return $this->responsesCall(
            model: Env::get('OPENAI_MODEL_ARTICLE', 'o4-mini'),
            inputBlocks: $inputBlocks,
            schemaName: 'article_schema',
            schema: $schema,
            temperature: (float)($payload['temperature'] ?? 0.45)
        );
    }

    /** Generate keyword ideas; tolerant to multiple JSON shapes */
    public function generateIdeas(string $lang, array $seeds, int $count): array
    {
        $schema = json_decode(file_get_contents(__DIR__ . '/../../schema/ideas.schema.json'), true);

        $system = "Generate unique, high-intent SEO ideas in {$lang} about camel milk. Return JSON only; diverse angles; no duplicates.";
        $userJson = json_encode([
            'task' => 'seed_ideas',
            'language' => $lang,
            'seed_topics' => $seeds,
            'count' => $count
        ], JSON_UNESCAPED_UNICODE);

        $inputBlocks = [
            ['role' => 'system', 'content' => [['type' => 'input_text', 'text' => $system]]],
            ['role' => 'user',   'content' => [['type' => 'input_text', 'text' => "USER_PAYLOAD_JSON:\n".$userJson]]],
        ];

        $out = $this->responsesCall(
            model: Env::get('OPENAI_MODEL_UTIL', Env::get('OPENAI_MODEL_ARTICLE', 'o4-mini')),
            inputBlocks: $inputBlocks,
            schemaName: 'ideas_schema',
            schema: $schema ?: ['type' => 'object'],
            temperature: 0.4
        );

        $ideas = $this->normalizeIdeas($out);
        if (!$ideas) {
            Logger::info('generateIdeas returned empty after normalization', [
                'lang' => $lang,
                'seeds_sample' => array_slice($seeds, 0, 5),
                'raw_keys' => array_keys((array)$out),
            ]);
        }
        return $ideas;
    }

    private function responsesCall(string $model, array $inputBlocks, string $schemaName, array $schema, float $temperature): array
    {
        $apiKey = Env::get('OPENAI_API_KEY', '');
        if ($apiKey === '') throw new \RuntimeException('OPENAI_API_KEY is not set.');

        // Primary: Structured Outputs
        $payloadStructured = [
            'model' => $model,
            'input' => $inputBlocks,
            'temperature' => $temperature,
            'response_format' => [ // official parameter for structured outputs
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $schemaName,
                    'schema' => $schema,
                    'strict' => true
                ]
            ]
        ];

        try {
            $res = $this->http->post('responses', [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payloadStructured
            ]);
            // Some models intermittently return empty output_text; decode more robustly:
            try {
                return $this->extractJson($res);
            } catch (\Throwable $decodeFail) {
                Logger::info('Primary decode failed; retrying with json_object', ['err' => $decodeFail->getMessage()]);
                // Fallback A: ask again with json_object
                $payloadJsonMode = $payloadStructured;
                $payloadJsonMode['response_format'] = ['type' => 'json_object'];

                $res2 = $this->http->post('responses', [
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => $payloadJsonMode
                ]);
                try {
                    return $this->extractJson($res2);
                } catch (\Throwable $decodeFail2) {
                    Logger::info('json_object decode failed; retrying with fallback model', ['err' => $decodeFail2->getMessage()]);
                    // Fallback B: switch model (e.g., o4-mini) if different
                    $fallback = Env::get('OPENAI_MODEL_FALLBACK', 'o4-mini');
                    if ($fallback && $fallback !== $model) {
                        $payloadJsonMode['model'] = $fallback;
                        $res3 = $this->http->post('responses', [
                            'headers' => [
                                'Authorization' => 'Bearer '.$apiKey,
                                'Content-Type'  => 'application/json',
                            ],
                            'json' => $payloadJsonMode
                        ]);
                        return $this->extractJson($res3);
                    }
                    throw $decodeFail2;
                }
            }
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode() ?? 0;
            $body   = $e->hasResponse() ? (string)$e->getResponse()->getBody() : '';
            Logger::error('OpenAI structured call failed', ['status' => $status, 'body' => $body]);
            throw new \RuntimeException('OpenAI request failed: '.$status);
        }
    }

    private function extractJson(ResponseInterface $res): array
    {
        $data = json_decode((string)$res->getBody(), true);
        if (!is_array($data)) throw new \RuntimeException('OpenAI returned invalid JSON.');

        // 1) Preferred: output_text
        $jsonText = $data['output_text'] ?? null;

        // 2) Robust harvest from outputs array if empty
        if (!is_string($jsonText) || trim($jsonText) === '') {
            $harvest = [];
            if (isset($data['output']) && is_array($data['output'])) {
                foreach ($data['output'] as $o) {
                    $content = $o['content'] ?? null;
                    if (!is_array($content)) continue;
                    foreach ($content as $c) {
                        // common shapes: ['type'=>'output_text','text'=>'...'] or ['type'=>'text','text'=>'...']
                        if (isset($c['text']) && is_string($c['text']) && trim($c['text']) !== '') {
                            $harvest[] = $c['text'];
                        }
                    }
                }
            }
            $jsonText = trim(implode("\n", $harvest));
        }

        if (!is_string($jsonText) || $jsonText === '') {
            Logger::error('OpenAI response missing text; sample', ['sample' => substr(json_encode($data), 0, 1000)]);
            throw new \RuntimeException('OpenAI response missing text.');
        }

        $out = json_decode($jsonText, true);
        if (!is_array($out)) {
            Logger::error('Structured output not JSON', ['text' => substr($jsonText, 0, 700)]);
            throw new \RuntimeException('Structured output not JSON.');
        }
        return $out;
    }

    /** Accepts {ideas:[...]}, [ ... ], or {items:[...]} and normalizes key styles */
    private function normalizeIdeas(array $out): array
    {
        $candidate = [];
        if (isset($out['ideas']) && is_array($out['ideas']))       $candidate = $out['ideas'];
        elseif (isset($out['items']) && is_array($out['items']))   $candidate = $out['items'];
        elseif (array_is_list($out))                               $candidate = $out;
        else return [];

        $norm = [];
        foreach ($candidate as $i) {
            if (!is_array($i)) continue;

            $title = trim((string)($i['title'] ?? $i['idea'] ?? $i['headline'] ?? ''));
            $pk    = trim((string)($i['primary_keyword'] ?? $i['primaryKeyword'] ?? $i['primary'] ?? $i['keyword'] ?? ''));
            if ($pk === '' && $title !== '') $pk = $title;
            if ($title === '' || $pk === '') continue;

            $sk = $i['supporting_keywords'] ?? ($i['supportingKeywords'] ?? ($i['keywords'] ?? []));
            if (is_string($sk)) {
                $sk = array_values(array_filter(array_map('trim', explode(',', $sk))));
            } elseif (!is_array($sk)) {
                $sk = [];
            } else {
                $tmp = [];
                foreach ($sk as $s) $tmp[] = trim((string)$s);
                $sk = array_values(array_filter($tmp));
            }

            $angle  = trim((string)($i['angle'] ?? ($i['category'] ?? '')));
            $intent = trim((string)($i['intent'] ?? 'informational'));

            $norm[] = [
                'title' => $title,
                'primary_keyword' => $pk,
                'supporting_keywords' => $sk,
                'angle' => $angle,
                'intent' => $intent,
            ];
        }

        $seen = [];
        $out = [];
        foreach ($norm as $n) {
            $key = mb_strtolower(preg_replace('/\s+/', ' ', $n['title'])) . '|' . mb_strtolower($n['primary_keyword']);
            if (isset($seen[$key])) continue;
            $seen[$key] = 1;
            $out[] = $n;
        }
        return $out;
    }
}
