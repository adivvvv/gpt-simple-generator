<?php
// src/Controllers/SeedKeywordsController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Http;
use App\Support\Validator;
use App\Services\OpenAIService;

final class SeedKeywordsController
{
    public function handle(): void
    {
        $body   = Http::body();
        $lang   = (string)($body['lang'] ?? '');
        $seeds  = array_values(array_filter((array)($body['seed_topics'] ?? [])));
        $count  = (int)($body['count'] ?? 100); // generate N ideas now (repeat to reach 1000)

        if (!Validator::lang($lang) || empty($seeds) || $count < 1 || $count > 200) {
            Http::json(['error' => 'Invalid payload'], 422);
        }

        $openai = new OpenAIService();
        $ideas  = $openai->generateIdeas($lang, $seeds, $count);

        Http::json(['lang'=>$lang, 'count'=>count($ideas), 'ideas'=>$ideas], 200);
    }
}
