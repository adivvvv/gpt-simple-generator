<?php
// src/Support/Env.php
declare(strict_types=1);

namespace App\Support;

use Dotenv\Dotenv;

final class Env
{
    /** Boot dotenv and ensure values are available via $_ENV/$_SERVER and getenv(). */
    public static function boot(string $baseDir): void
    {
        date_default_timezone_set('UTC');

        if (is_file($baseDir . '/.env')) {
            $dotenv = Dotenv::createImmutable($baseDir);
            $dotenv->load();
        }

        // Mirror to process environment for getenv()
        foreach ($_ENV as $k => $v) {
            if (\getenv($k) === false) {
                @putenv($k . '=' . $v);
            }
            $_SERVER[$k] = $v;
        }

        date_default_timezone_set(self::get('APP_TIMEZONE', 'UTC'));

        // Ensure storage dirs exist
        $cacheDir = $baseDir . '/' . self::get('CACHE_DIR', 'storage/cache');
        $logDir   = $baseDir . '/' . self::get('LOG_DIR',   'storage/logs');
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        if (!is_dir($logDir))   @mkdir($logDir,   0775, true);
    }

    /** Read env from $_ENV/$_SERVER, fall back to getenv(), then default. */
    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $_ENV))    return (string) $_ENV[$key];
        if (array_key_exists($key, $_SERVER)) return (string) $_SERVER[$key];
        $v = \getenv($key);
        return ($v === false) ? $default : (string) $v;
    }
}