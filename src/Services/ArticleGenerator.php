<?php
declare(strict_types=1);

namespace App\Services;

final class ArticleGenerator
{
    public function __construct(private readonly OpenAIService $openai) {}

    public function generate(array $payload, array $pubmedRefs): array
    {
        $schema = json_decode(file_get_contents(__DIR__ . '/../../schema/article.schema.json'), true);

        // Ensure a slug hint exists
        $payload['keywords'] = array_values(array_unique(array_map('strval', $payload['keywords'])));

        return $this->openai->generateArticle($payload, $pubmedRefs, $schema);
    }
}
