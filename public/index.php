<?php
declare(strict_types=1);

use App\Support\Env;
use App\Support\Http;
use App\Controllers\GenerateController;
use App\Controllers\SeedKeywordsController;

require __DIR__ . '/../vendor/autoload.php';
Env::boot(__DIR__ . '/../');

Http::cors(); // can be disabled if only server→server

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    Http::requireJson();
    Http::requireAuth(getenv('API_KEY') ?: '');

    if ($method === 'POST' && $path === '/generate') {
        (new GenerateController())->handle();
    } elseif ($method === 'POST' && $path === '/seed_keywords') {
        (new SeedKeywordsController())->handle();
    } else {
        Http::json(['error' => 'Not found'], 404);
    }
} catch (Throwable $e) {
    App\Support\Logger::error($e->getMessage(), ['trace' => $e->getTraceAsString()]);
    Http::json(['error' => 'Server error'], 500);
}