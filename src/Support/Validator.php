<?php
declare(strict_types=1);

namespace App\Support;

final class Validator
{
    public static function lang(string $lang): bool
    {
        return in_array($lang, ['en','de','fr','it','es','sv','fi','nl','pl','cs'], true);
    }
}