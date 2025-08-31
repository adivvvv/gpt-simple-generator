<?php
declare(strict_types=1);

namespace App\Support;

use Dotenv\Dotenv;

final class Env
{
    public static function boot(string $baseDir): void
    {
        date_default_timezone_set('UTC');
        if (is_file($baseDir.'/.env')) {
            $dotenv = Dotenv::createImmutable($baseDir);
            $dotenv->load();
        }
        date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'UTC');

        // Ensure storage dirs
        foreach ([getenv('CACHE_DIR') ?: 'storage/cache', getenv('LOG_DIR') ?: 'storage/logs'] as $dir) {
            if (!is_dir($baseDir . '/' . $dir)) {
                @mkdir($baseDir . '/' . $dir, 0775, true);
            }
        }
    }
}