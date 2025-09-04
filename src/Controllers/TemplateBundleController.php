<?php
// src/Controllers/TemplateBundleController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Http;
use App\Support\Validator;
use App\Support\Logger;
use App\Services\OpenAIService;
use App\Services\TemplateSynth;

final class TemplateBundleController
{
    public function handle(): void
    {
        $b = Http::body();
        $lang  = (string)($b['lang'] ?? 'en');
        $plan  = (array)($b['plan'] ?? []);
        $seed  = (string)($b['seed'] ?? ($plan['seed'] ?? bin2hex(random_bytes(6))));
        $flags = array_values(array_filter((array)($b['styleFlags'] ?? ['clean','airy','modern'])));

        if (!Validator::lang($lang)) {
            Http::json(['error'=>'Invalid lang'], 422);
        }

        $open = new OpenAIService();

        // If caller didn't send a plan, create one first.
        if (!$plan) {
            $plan = $open->generateTemplatePlan($lang, $seed, $flags);
        }

        // Server-side synthesizer renders the final bundle deterministically from plan.
        $synth = new TemplateSynth();
        $bundle = $synth->buildBundle($plan, $lang);

        Http::json(['ok'=>true,'bundle'=>$bundle,'plan'=>$plan], 200);
    }
}