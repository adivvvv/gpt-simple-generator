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
     * Required fields include: title, slug, summary, body_markdown, faq, references, tags, subject
     */
    public function generateArticle(array $payload, array $refs, array $schema): array
    {
        $lang      = (string)$payload['lang'];
        $subject   = (string)($payload['subject'] ?? '');
        $lead      = (string)($payload['leadStyle'] ?? 'surprising-stat');
        $minSp     = (int)($payload['minSentencesPerParagraph'] ?? 4);
        $pmidMode  = (string)($payload['pmidMode'] ?? 'limited'); // none|limited
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
            "SUBJECT (must be copied verbatim into the 'subject' field in the JSON output): {$subject}",
            "Goals:",
            "- Write an article specifically about the SUBJECT above.",
            "- Start with a UNIQUE, non-generic introduction using the lead style: {$lead}.",
            "- Structure: target {$payload['paragraphs']} paragraphs; EACH paragraph must have at least {$minSp} sentences.",
            "- Summarize evidence and mechanisms clearly and neutrally; no medical advice; EU-compliant tone.",
            "- FAQs: include {$payload['faqCount']} Q&A pairs (plain text).",
            "- Citations: {$pmidPolicyText}",
            "- Never fabricate PMIDs or study data. If uncertain, omit inline citation.",
            $banListText,
            "Constraints:",
            "- Text-only. No images, no tables.",
            "- Use consistent terminology in {$lang}.",
            "- If you include PMIDs inline, cite like [PMID:12345678].",
            "",
            "VERY IMPORTANT: Return ONLY a single JSON object that EXACTLY matches the provided JSON schema. Do not include any extra keys. Do not echo the input payload."
        ]);

        $userJson = json_encode([
            'task' => 'write_article',
            'language' => $lang,
            'subject' => $subject,
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
            temperature: (float)($payload['temperature'] ?? 0.45)
        );
    }

    /** Ideas generator unchanged (kept tolerant on shapes) */
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

        if (isset($out['ideas']) && is_array($out['ideas'])) return $out['ideas'];
        if (isset($out['items']) && is_array($out['items'])) return $out['items'];
        if (array_is_list($out)) return $out;
        return [];
    }

    /** Translate a subject/title to target $lang and return the translated string. */
    public function translateSubject(string $subject, string $lang): string
    {
        $schema = [
            'type' => 'object',
            'required' => ['subject'],
            'additionalProperties' => false,
            'properties' => [
                'subject' => ['type'=>'string']
            ]
        ];

        $system = "Translate the given subject/title into {$lang}. Preserve meaning, brevity, and key terms (e.g., 'camel milk'). Return ONLY JSON with a single field 'subject'.";
        $inputBlocks = [
            ['role'=>'system','content'=>[['type'=>'input_text','text'=>$system]]],
            ['role'=>'user','content'=>[['type'=>'input_text','text'=>"SUBJECT:\n".$subject]]],
        ];

        try {
            $out = $this->responsesCall(
                model: Env::get('OPENAI_MODEL_UTIL', Env::get('OPENAI_MODEL_ARTICLE', 'gpt-5-mini')),
                inputBlocks: $inputBlocks,
                schemaName: 'translate_schema',
                schema: $schema,
                temperature: 0.2
            );
            $candidate = (string)($out['subject'] ?? '');
            return $candidate !== '' ? $candidate : $subject;
        } catch (\Throwable $e) {
            Logger::error('translateSubject failed', ['err'=>$e->getMessage()]);
            return $subject;
        }
    }

    /**
     * Generate a template plan.
     */
    public function generateTemplatePlan(string $lang, string $seed, array $styleFlags): array
    {
        $schema = json_decode(file_get_contents(__DIR__ . '/../../schema/template_plan.schema.json'), true);
        if (!is_array($schema)) {
            throw new \RuntimeException('template_plan.schema.json missing');
        }

        $system = implode("\n", [
            "You are a senior web theme designer.",
            "Goal: Propose a small design system for a text-only, image-free, cookie-free, Tailwind-like site.",
            "Constraints:",
            "- No external assets, no remote fonts, no scripts.",
            "- Prioritize readability: ~60–70 characters per line, generous line-height.",
            "- Palette must be accessible; avoid low-contrast pairings (aim for WCAG AA).",
            "- Header must include a 'Latest 10' rail variant when header_variant='rail'.",
            "- Provide short copy for hero title/subtitle (language: {$lang}).",
            "",
            "Return ONLY JSON per schema; no explanations."
        ]);

        $user = [
            'task'       => 'template_plan',
            'language'   => $lang,
            'seed'       => $seed,
            'styleFlags' => $styleFlags
        ];

        $inputBlocks = [
            ['role'=>'system','content'=>[['type'=>'input_text','text'=>$system]]],
            ['role'=>'user','content'=>[['type'=>'input_text','text'=>"USER_PAYLOAD_JSON:\n".json_encode($user, JSON_UNESCAPED_UNICODE)]]],
        ];

        $out = $this->responsesCall(
            model: Env::get('OPENAI_MODEL_UTIL', Env::get('OPENAI_MODEL_ARTICLE', 'gpt-5-mini')),
            inputBlocks: $inputBlocks,
            schemaName: 'template_plan',
            schema: $schema,
            temperature: 0.55 // allow creative variety
        );

        // Fairly strict: ensure minimal keys exist
        foreach (['seed','name','prefix','palette','type_scale','layout','copy'] as $k) {
            if (!isset($out[$k])) {
                Logger::error('template plan missing key', ['key'=>$k,'out'=>$out]);
                throw new \RuntimeException('Template plan missing key: '.$k);
            }
        }
        return $out;
    }

    private function responsesCall(string $model, array $inputBlocks, string $schemaName, array $schema, float $temperature): array
    {
        $apiKey = Env::get('OPENAI_API_KEY', '');
        if ($apiKey === '') {
            throw new \RuntimeException('OPENAI_API_KEY is not set.');
        }

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
        $candidate = $data['output_text'] ?? null;
        if (is_string($candidate) && $candidate !== '') {
            $try = json_decode($candidate, true);
            if (is_array($try)) return $try;
        }

        // Robust path: scan content blocks and pick the FIRST valid JSON block.
        if (isset($data['output']) && is_array($data['output'])) {
            foreach ($data['output'] as $o) {
                $content = $o['content'] ?? null;
                if (!is_array($content)) continue;
                foreach ($content as $c) {
                    $txt = $c['text'] ?? null;
                    if (!is_string($txt) || trim($txt) === '') continue;
                    $decoded = json_decode($txt, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        }

        // Nothing parseable:
        Logger::error('OpenAI unexpected shape', ['sample' => substr(json_encode($data), 0, 1000)]);
        throw new \RuntimeException('OpenAI response missing valid JSON.');
    }
}