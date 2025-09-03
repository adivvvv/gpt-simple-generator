<?php
// src/Controllers/IdeasSeedController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Http;
use App\Support\Validator;
use App\Support\Logger;
use App\Support\Env;
use App\Services\OpenAIService;
use App\Services\IdeaStore;
use Throwable;

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

        try {
            while ($store->count() < $target && $guard < 50) {
                $ideas = $open->generateIdeas($lang, $seeds, $batch);
                if (!is_array($ideas) || count($ideas) === 0) {
                    Logger::info('No ideas returned this round', ['lang' => $lang, 'batch' => $batch]);
                    $guard++;
                    $batch = max(10, (int)floor($batch / 2));
                    continue;
                }
                $added = $store->addIdeas($ideas, 2000);
                $addedTotal += $added;
                $guard++;
                if ($added === 0) break; // all duplicates reached cap
            }
        } catch (Throwable $e) {
            Logger::error('ideas_seed failed', ['err' => $e->getMessage()]);
            $dbg = Env::get('APP_DEBUG', 'false') === 'true';
            Http::json($dbg ? ['error' => 'Upstream generation failed', 'details' => $e->getMessage()] : ['error' => 'Upstream generation failed'], 502);
        }

        Http::json([
            'lang'   => $lang,
            'target' => $target,
            'count'  => $store->count(),
            'added'  => $addedTotal
        ], 200);
    }
}
