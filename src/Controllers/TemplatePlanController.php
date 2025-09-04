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
        $lang  = (string)($b['lang'] ?? 'en');
        $seed  = (string)($b['seed'] ?? bin2hex(random_bytes(6)));
        $flags = array_values(array_filter((array)($b['styleFlags'] ?? ['clean','airy','modern'])));

        if (!Validator::lang($lang)) {
            Http::json(['error'=>'Invalid lang'], 422);
        }

        $open = new OpenAIService();
        $plan = $open->generateTemplatePlan($lang, $seed, $flags);

        Http::json(['ok'=>true,'plan'=>$plan], 200);
    }
}
