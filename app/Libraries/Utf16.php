<?php

namespace App\Libraries;

/**
 * UTF-16 code unit helpers. The frontend is JavaScript, so error offsets
 * (`startIndex`/`endIndex`) are expressed in UTF-16 code units, like `String.prototype.slice`.
 */
final class Utf16
{
    public static function length(string $text): int
    {
        return intdiv(strlen(self::encode($text)), 2);
    }

    /** Equivalent of JavaScript `text.slice($start, $end)`. */
    public static function slice(string $text, int $start, int $end): string
    {
        return mb_convert_encoding(substr(self::encode($text), $start * 2, ($end - $start) * 2), 'UTF-8', 'UTF-16LE');
    }

    private static function encode(string $text): string
    {
        return mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
    }
}
