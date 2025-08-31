<?php
declare(strict_types=1);

namespace App\Support;

final class Http
{
    public static function cors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    public static function requireJson(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (($_SERVER['CONTENT_TYPE'] ?? '') && !str_contains($_SERVER['CONTENT_TYPE'], 'application/json')) {
            self::json(['error' => 'Use application/json'], 415);
        }
    }

    public static function requireAuth(string $serverKey): void
    {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');
        $key = '';
        if (str_starts_with($hdr, 'Bearer ')) $key = substr($hdr, 7);
        elseif (!empty($_SERVER['HTTP_X_API_KEY'])) $key = $_SERVER['HTTP_X_API_KEY'];

        if (!$serverKey || $key !== $serverKey) {
            self::json(['error' => 'Unauthorized'], 401);
        }
    }

    public static function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function json(array $payload, int $code = 200): void
    {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
