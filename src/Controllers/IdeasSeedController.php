<?php
// src/Controllers/IdeasSeedController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Http;
use App\Support\Validator;
use App\Services\OpenAIService;
use App\Services\IdeaStore;

final class IdeasSeedController
{
    public function handle(): void
    {
        $body   = Http::body();
        $lang   = (string)($body['lang'] ?? '');
        $target = (int)($body['target'] ?? 1000);
        $batch  = (int)($body['batch'] ?? 100);
        $seeds  = array_values(array_filter((array)($body['seed_topics'] ?? ['camel milk'])));

        if (!Validator::lang($lang) || $target < 100 || $batch < 10) {
            Http::json(['error' => 'Invalid payload'], 422);
        }

        $store = new IdeaStore($lang);
        $open  = new OpenAIService();

        $addedTotal = 0;
        $guard = 0;

        while ($store->count() < $target && $guard < 50) {
            $ideas = $open->generateIdeas($lang, $seeds, $batch);
            $added = $store->addIdeas($ideas, 2000);
            $addedTotal += $added;
            $guard++;
            if ($added === 0) break; // nothing new; avoid infinite loops
        }

        Http::json([
            'lang' => $lang,
            'target' => $target,
            'count' => $store->count(),
            'added' => $addedTotal
        ], 200);
    }
}
