<?php
// src/Controllers/TemplateBundleController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Http;
use App\Support\Validator;
use App\Support\Env;
use App\Support\Logger;
use App\Services\OpenAIService;
use App\Services\TemplateSynth;

final class TemplateBundleController
{
    public function handle(): void
    {
        $b = Http::body();

        $lang      = (string)($b['lang'] ?? 'en');
        $plan      = (array)($b['plan'] ?? []);
        $randomize = (bool)($b['randomize'] ?? false);

        if (isset($b['seed']) && is_string($b['seed']) && $b['seed'] !== '') {
            $seed = (string)$b['seed'];
        } elseif (isset($plan['seed']) && is_string($plan['seed']) && $plan['seed'] !== '') {
            $seed = (string)$plan['seed'];
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

        // If caller didn't send a plan, create one first.
        if (!$plan) {
            $plan = $open->generateTemplatePlan($lang, $seed, $flags);
        }

        // Never allow model to override effective seed
        $plan['seed'] = $seed;

        // Server-side synthesizer renders the final bundle deterministically from plan.
        $synth  = new TemplateSynth();
        $bundle = $synth->buildBundle($plan, $lang);

        if ($randomize) {
            @header('Cache-Control: no-store, no-cache, must-revalidate');
            @header('Pragma: no-cache');
        }

        Http::json(['ok' => true, 'bundle' => $bundle, 'plan' => $plan], 200);
    }
}
