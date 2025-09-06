<?php
// src/Controllers/TemplatePlanController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Http;
use App\Support\Validator;
use App\Support\Env;
use App\Support\Logger;
use App\Services\OpenAIService;

final class TemplatePlanController
{
    public function handle(): void
    {
        $b = Http::body();

        $lang      = (string)($b['lang'] ?? 'en');
        $randomize = (bool)($b['randomize'] ?? false);

        // If caller supplied a seed, use it; otherwise create a clearly random, human-readable one
        if (isset($b['seed']) && is_string($b['seed']) && $b['seed'] !== '') {
            $seed = (string)$b['seed'];
        } else {
            $seed = 'seed-' . (string)random_int(1000000000, 9999999999);
        }

        // Flags: if provided, honor; if randomize and none provided, sample a few to broaden variety.
        $flagsIn = (array)($b['styleFlags'] ?? []);
        if ($randomize && !$flagsIn) {
            $pool = ['clean','airy','modern','serifish','boxed','outlined','lined'];
            shuffle($pool);
            $flags = array_slice($pool, 0, 2);
        } else {
            $flags = array_values(array_filter($flagsIn));
        }

        if (!Validator::lang($lang)) {
            Http::json(['error' => 'Invalid lang'], 422);
        }

        $open = new OpenAIService();
        $plan = $open->generateTemplatePlan($lang, $seed, $flags);

        // Never trust model to override our seed
        $plan['seed'] = $seed;

        if ($randomize) {
            // prevent intermediary caches from replaying a previous bundle
            @header('Cache-Control: no-store, no-cache, must-revalidate');
            @header('Pragma: no-cache');
        }

        Http::json(['ok' => true, 'plan' => $plan], 200);
    }
}