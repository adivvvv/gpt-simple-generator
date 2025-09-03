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

    /**
     * Generate a full article as strict JSON per article.schema.json
     * Extra controls:
     *  - leadStyle: string (e.g., "myth-busting", "surprising-stat", "question", "historical-note", "case-context", "analogy")
     *  - minSentencesPerParagraph: int
     *  - pmidMode: "none"|"limited"
     *  - maxInlinePmids: int (used when pmidMode = "limited")
     *  - banPhrases: string[] (phrases to avoid anywhere, esp. in the intro)
     */
    public function generateArticle(array $payload, array $refs, array $schema): array
    {
        $lang   = (string)$payload['lang'];
        $lead   = (string)($payload['leadStyle'] ?? 'surprising-stat');
        $minSp  = (int)($payload['minSentencesPerParagraph'] ?? 4);
        $pmidMode = (string)($payload['pmidMode'] ?? 'limited'); // none|limited
        $maxInline = (int)($payload['maxInlinePmids'] ?? 3);
        $banPhrases = (array)($payload['banPhrases'] ?? []);

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
            "- Start with a UNIQUE, non-generic introduction using the lead style: {$lead}. Do NOT reuse boilerplate.",
            "- Use a varied opening device (e.g., a pointed question, surprising data, myth-busting, short historical note, practical scenario, or crisp analogy).",
            "- Structure: target {$payload['paragraphs']} paragraphs; EACH paragraph must have at least {$minSp} sentences (full-stops, not fragments).",
            "- Summarize evidence and mechanisms clearly and neutrally; no medical advice; EU-compliant tone.",
            "- FAQs: include {$payload['faqCount']} questions/answers (plain text).",
            "- Citations: {$pmidPolicyText}",
            "- Never fabricate PMIDs or study data. If uncertain, omit the inline citation.",
            $banListText,
            "Constraints:",
            "- Text-only. No images, no tables.",
            "- Use consistent terminology in {$lang}.",
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
            [
                'role' => 'system',
                'content' => [
                    ['type' => 'input_text', 'text' => $system]
                ]
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => "USER_PAYLOAD_JSON:\n" . $userJson]
                ]
            ]
        ];

        return $this->responsesCall(
            model: Env::get('OPENAI_MODEL_ARTICLE', 'gpt-5-mini'),
            inputBlocks: $inputBlocks,
            schemaName: 'article_schema',
            schema: $schema,
            temperature: (float)($payload['temperature'] ?? 0.45) // a bit higher for intro diversity
        );
    }

    /** Generate keyword ideas; tolerant to different shapes; still enforces your schema upstream */
    public function generateIdeas(string $lang, array $seeds, int $count): array
    {
        $schema = json_decode(file_get_contents(__DIR__ . '/../../schema/ideas.schema.json'), true);

        $system = "Generate unique, high-intent SEO ideas in {$lang} about camel milk. Return ONLY JSON matching schema; no duplicates; diverse angles.";
        $userJson = json_encode([
            'task' => 'seed_ideas',
            'language' => $lang,
            'seed_topics' => $seeds,
            'count' => $count
        ], JSON_UNESCAPED_UNICODE);

        $inputBlocks = [
            [
                'role' => 'system',
                'content' => [
                    ['type' => 'input_text', 'text' => $system]
                ]
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => "USER_PAYLOAD_JSON:\n" . $userJson]
                ]
            ]
        ];

        $out = $this->responsesCall(
            model: Env::get('OPENAI_MODEL_UTIL', Env::get('OPENAI_MODEL_ARTICLE', 'gpt-5-mini')),
            inputBlocks: $inputBlocks,
            schemaName: 'ideas_schema',
            schema: $schema,
            temperature: 0.4
        );

        // Prefer strict schema field, but be tolerant
        if (isset($out['ideas']) && is_array($out['ideas'])) {
            return $out['ideas'];
        }
        if (isset($out['items']) && is_array($out['items'])) {
            return $out['items'];
        }
        if (array_is_list($out)) {
            return $out;
        }
        return [];
    }

    private function responsesCall(string $model, array $inputBlocks, string $schemaName, array $schema, float $temperature): array
    {
        $apiKey = Env::get('OPENAI_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY is not set.');
        }

        // IMPORTANT: Use the payload style that worked for you previously:
        $payloadStructured = [
            'model' => $model,
            'input' => $inputBlocks,
            'temperature' => $temperature,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => $schemaName,
                        'schema' => $schema,
                        'strict' => true
                    ]
                ]
            ]
        ];

        try {
            $res = $this->http->post('responses', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payloadStructured
            ]);
            return $this->extractJson($res);
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode() ?? 0;
            $body   = $e->hasResponse() ? (string)$e->getResponse()->getBody() : '';
            Logger::error('OpenAI structured call failed', ['status' => $status, 'body' => $body]);

            if (in_array($status, [400, 422], true)) {
                // Fallback to generic JSON mode
                $payloadJsonMode = $payloadStructured;
                $payloadJsonMode['text']['format'] = ['type' => 'json_object'];

                try {
                    $res2 = $this->http->post('responses', [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $apiKey,
                            'Content-Type'  => 'application/json',
                        ],
                        'json' => $payloadJsonMode
                    ]);
                    return $this->extractJson($res2);
                } catch (RequestException $e2) {
                    Logger::error('OpenAI json_object call failed', [
                        'status' => $e2->getResponse()?->getStatusCode(),
                        'body'   => $e2->hasResponse() ? (string)$e2->getResponse()->getBody() : ''
                    ]);

                    // Last resort: fallback model (still using json_object)
                    $fallback = Env::get('OPENAI_MODEL_FALLBACK', 'gpt-4o-mini');
                    if ($fallback && $fallback !== $model) {
                        $payloadJsonMode['model'] = $fallback;
                        $res3 = $this->http->post('responses', [
                            'headers' => [
                                'Authorization' => 'Bearer ' . $apiKey,
                                'Content-Type'  => 'application/json',
                            ],
                            'json' => $payloadJsonMode
                        ]);
                        return $this->extractJson($res3);
                    }
                }
            }

            $msg = 'OpenAI request failed: ' . $status;
            if (Env::get('APP_DEBUG', 'false') === 'true' && $body) {
                $msg .= ' — ' . mb_strimwidth(preg_replace('/\s+/', ' ', $body), 0, 900, '…');
            }
            throw new \RuntimeException($msg);
        }
    }

    private function extractJson(ResponseInterface $res): array
    {
        $data = json_decode((string)$res->getBody(), true);
        if (!is_array($data)) {
            throw new \RuntimeException('OpenAI returned invalid JSON.');
        }

        // Preferred path
        $jsonText = $data['output_text'] ?? null;

        // Robust harvest: walk all content blocks for 'text' fields if output_text is empty
        if (!is_string($jsonText) || trim($jsonText) === '') {
            $harvest = [];
            if (isset($data['output']) && is_array($data['output'])) {
                foreach ($data['output'] as $o) {
                    $content = $o['content'] ?? null;
                    if (!is_array($content)) continue;
                    foreach ($content as $c) {
                        if (isset($c['text']) && is_string($c['text']) && trim($c['text']) !== '') {
                            $harvest[] = $c['text'];
                        }
                    }
                }
            }
            $jsonText = trim(implode("\n", $harvest));
        }

        if (!is_string($jsonText) || $jsonText === '') {
            Logger::error('OpenAI unexpected shape', ['sample' => substr(json_encode($data), 0, 1000)]);
            throw new \RuntimeException('OpenAI response missing text.');
        }

        $out = json_decode($jsonText, true);
        if (!is_array($out)) {
            Logger::error('Structured output not JSON', ['text' => substr($jsonText, 0, 700)]);
            throw new \RuntimeException('Structured output not JSON.');
        }
        return $out;
    }
}
