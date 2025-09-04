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

        // Stronger instruction: explicitly list required keys
        $system = implode("\n", [
            "You are a senior web theme designer.",
            "Goal: Propose a small design system for a text-only, image-free, cookie-free, Tailwind-like site.",
            "MUST OUTPUT JSON with EXACT keys: seed, name, prefix, palette, type_scale, layout, copy.",
            "Rules:",
            "- Copy seed from user payload.",
            "- name: short human-friendly theme name (2–3 words, Title Case).",
            "- prefix: lowercase CSS class prefix (<=15 chars), pattern ^[a-z][a-z0-9-]{1,14}$ (e.g., cw-a7).",
            "- palette: bg, card, fg, muted, accent, accent_ink, border (accessible contrast, WCAG AA).",
            "- type_scale: base_px(15..19), leading(1.45..1.8), measure_ch(58..76).",
            "- layout: header_variant(rail|stacked|double), hero_variant(center-thin|left-stacked|boxed),",
            "          card_variant(soft|outlined|lined), pagination_variant(minimal|pill|boxed), icons(svg|unicode).",
            "- copy: hero_title, hero_subtitle, cta_label (language: {$lang}).",
            "- No external assets or remote fonts. No JS. Text-only.",
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
            temperature: 0.55
        );

        // Harden required fields
        // 1) seed must echo input
        $out['seed'] = $out['seed'] ?? $seed;

        // 2) prefix fallback (valid + deterministic)
        if (empty($out['prefix']) || !preg_match('/^[a-z][a-z0-9-]{1,14}$/', (string)$out['prefix'])) {
            $out['prefix'] = 'cw-' . substr(preg_replace('/[^a-z0-9]/', '', strtolower(hash('sha1', $seed))), 0, 10);
        }

        // 3) name fallback (deterministic, readable)
        if (empty($out['name'])) {
            $out['name'] = $this->themeNameFromSeed($seed, $styleFlags, $lang);
        }

        // Final strict check (will now pass)
        foreach (['seed','name','prefix','palette','type_scale','layout','copy'] as $k) {
            if (!isset($out[$k])) {
                \App\Support\Logger::error('template plan missing key', ['key'=>$k,'out'=>$out]);
                throw new \RuntimeException('Template plan missing key: '.$k);
            }
        }
        return $out;
    }

    /**
     * Deterministic, short theme name from seed + flags.
     * Example outputs: "Desert Olive", "Saffron Rail", "Quartz Serif".
     */
    private function themeNameFromSeed(string $seed, array $styleFlags, string $lang = 'en'): string
    {
        // NB: language-sensitive naming can be added later; keep EN now.
        $adjectives = [
            'Desert','Olive','Saffron','Quartz','Azure','Ivory','Cedar','Marble','Amber','Linen',
            'Slate','Moss','Drift','Velvet','Amberlite','Nimbus','Cinder','Aster','Wheat','Sienna'
        ];
        $nouns = [
            'Breeze','Rail','Serif','Canvas','Page','Note','Read','Column','Verse','Glyph',
            'Frame','Fold','Scroll','Ledger','Quill','Outline','Accent','Stream','Cluster','Atlas'
        ];
        // Pick indexes from seed hash
        $h = hexdec(substr(hash('sha1', $seed), 0, 8));
        $a = $adjectives[$h % count($adjectives)];
        $b = $nouns[($h >> 5) % count($nouns)];

        // Nudge for flags
        $flagNudge = '';
        $flags = array_map('strtolower', $styleFlags);
        if (in_array('serifish', $flags, true) && !in_array($b, ['Serif','Ledger','Quill'], true)) $b = 'Serif';
        if (in_array('boxed', $flags, true)   && !in_array($b, ['Frame','Canvas','Ledger'], true)) $flagNudge = ' Frame';
        if (in_array('airy', $flags, true)    && !in_array($a, ['Breeze','Nimbus'], true))         $a = 'Breeze';

        return trim("$a $b$flagNudge");
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

        // 1) Preferred: responses content blocks with type=output_json
        if (isset($data['output']) && is_array($data['output'])) {
            foreach ($data['output'] as $o) {
                if (!isset($o['content']) || !is_array($o['content'])) continue;
                foreach ($o['content'] as $c) {
                    // Modern structured path
                    if (($c['type'] ?? '') === 'output_json' && isset($c['json']) && is_array($c['json'])) {
                        return $c['json'];
                    }
                }
            }
        }

        // 2) Next best: top-level output_text
        if (isset($data['output_text']) && is_string($data['output_text']) && trim($data['output_text']) !== '') {
            $try = json_decode($data['output_text'], true);
            if (is_array($try)) return $try;
        }

        // 3) Fallback: scan any content[text] blocks for a JSON object
        if (isset($data['output']) && is_array($data['output'])) {
            foreach ($data['output'] as $o) {
                if (!isset($o['content']) || !is_array($o['content'])) continue;
                foreach ($o['content'] as $c) {
                    $txt = $c['text'] ?? null;
                    if (!is_string($txt) || trim($txt) === '') continue;
                    $decoded = json_decode($txt, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        }

        // Nothing parseable
        \App\Support\Logger::error('OpenAI unexpected shape', ['sample' => substr(json_encode($data), 0, 1000)]);
        throw new \RuntimeException('OpenAI response missing valid JSON.');
    }

}