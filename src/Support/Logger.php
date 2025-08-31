<?php
declare(strict_types=1);

namespace App\Support;

final class Logger
{
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }
    private static function write(string $level, string $message, array $context): void
    {
        $file = __DIR__ . '/../../' . (getenv('LOG_DIR') ?: 'storage/logs') . '/app.log';
        $line = sprintf("[%s] %s %s %s\n", date('c'), $level, $message, $context ? json_encode($context) : '');
        @file_put_contents($file, $line, FILE_APPEND);
    }
}
