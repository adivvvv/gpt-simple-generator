<?php
declare(strict_types=1);

use App\Support\Env;
use App\Support\Http;
use App\Support\Logger;
use App\Controllers\GenerateController;
use App\Controllers\SeedKeywordsController;

require __DIR__ . '/../vendor/autoload.php';
Env::boot(__DIR__ . '/../');

Http::cors(); // optional

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    // Health check (no auth) — safe fields only
    if ($method === 'GET' && $path === '/ping') {
        $hasApiKey      = Env::get('API_KEY', '') !== '' ? 'yes' : 'no';
        $hasOpenAIKey   = Env::get('OPENAI_API_KEY', '') !== '' ? 'yes' : 'no';
        $modelArticle   = Env::get('OPENAI_MODEL_ARTICLE', '');
        $modelUtil      = Env::get('OPENAI_MODEL_UTIL', '');
        $headers        = function_exists('getallheaders') ? getallheaders() : [];
        Http::json([
            'ok' => true,
            'env' => [
                'APP_ENV'  => Env::get('APP_ENV', ''),
                'APP_DEBUG'=> Env::get('APP_DEBUG', 'false'),
                'API_KEY'  => $hasApiKey,
                'OPENAI'   => ['has_key' => $hasOpenAIKey, 'article_model' => $modelArticle, 'util_model' => $modelUtil],
            ],
            'seen_headers' => array_intersect_key($headers, array_flip(['Authorization','X-API-Key','Content-Type']))
        ]);
    }

    // Auth for API routes
    if ($path === '/generate' || $path === '/seed_keywords') {
        Http::requireAuth(Env::get('API_KEY', ''));
    }

    if ($method === 'POST' && $path === '/generate') {
        Http::requireJson();
        (new GenerateController())->handle();
    } elseif ($method === 'POST' && $path === '/seed_keywords') {
        Http::requireJson();
        (new SeedKeywordsController())->handle();
    } else {
        Http::json(['error' => 'Not found'], 404);
    }
} catch (Throwable $e) {
    Logger::error($e->getMessage(), ['trace' => $e->getTraceAsString()]);
    $dbg = Env::get('APP_DEBUG', 'false') === 'true';
    Http::json($dbg ? ['error' => $e->getMessage()] : ['error' => 'Server error'], 500);
}