<?php
declare(strict_types=1);

namespace App\Services;

use OpenAI;

final class OpenAIService
{
    private \OpenAI\Client $client;

    public function __construct(?\OpenAI\Client $client = null)
    {
        $this->client = $client ?: OpenAI::client(getenv('OPENAI_API_KEY') ?: '');
    }

    /** Structured Outputs — Article JSON */
    public function generateArticle(array $payload, array $refs, array $schema): array
    {
        $instructions = implode("\n", [
            "You are CamelWay scientific editor.",
            "Write an original, human-sounding article in {$payload['lang']} about camel milk, text-only.",
            "Cite PubMed PMIDs inline like [PMID:12345678].",
            "Summarize evidence; no medical advice; EU style compliance.",
            "Target {$payload['paragraphs']} paragraphs; include {$payload['faqCount']} FAQs."
        ]);

        $input = [
            'task' => 'write_article',
            'language' => $payload['lang'],
            'keywords' => $payload['keywords'],
            'styleFlags' => $payload['styleFlags'],
            'specialRequirements' => $payload['specialRequirements'] ?? '',
            'references' => $refs,
        ];

        $res = $this->client->responses()->create([
            'model' => getenv('OPENAI_MODEL_ARTICLE') ?: 'gpt-5-mini',
            'instructions' => $instructions,
            'input' => json_encode($input, JSON_UNESCAPED_UNICODE),
            'temperature' => 0.3,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'article_schema',
                    'schema' => $schema,
                    'strict' => true
                ]
            ]
        ]);

        $json = trim($res->outputText);
        $out  = json_decode($json, true);
        if (!is_array($out)) {
            throw new \RuntimeException('Model returned non-JSON.');
        }
        return $out;
    }

    /** Structured Outputs — Keyword ideas JSON */
    public function generateIdeas(string $lang, array $seeds, int $count): array
    {
        $schema = json_decode(file_get_contents(__DIR__ . '/../../schema/ideas.schema.json'), true);

        $instructions = "Generate unique, high-intent SEO ideas in {$lang} about camel milk. Return ONLY JSON matching schema; no duplicates; diverse angles.";

        $input = [
            'task' => 'seed_ideas',
            'language' => $lang,
            'seed_topics' => $seeds,
            'count' => $count
        ];

        $res = $this->client->responses()->create([
            'model' => getenv('OPENAI_MODEL_UTIL') ?: (getenv('OPENAI_MODEL_ARTICLE') ?: 'gpt-5-mini'),
            'instructions' => $instructions,
            'input' => json_encode($input, JSON_UNESCAPED_UNICODE),
            'temperature' => 0.4,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'ideas_schema',
                    'schema' => $schema,
                    'strict' => true
                ]
            ]
        ]);

        $out = json_decode(trim($res->outputText), true);
        return $out['ideas'] ?? [];
    }
}
