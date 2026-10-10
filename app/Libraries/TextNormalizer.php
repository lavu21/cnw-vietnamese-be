<?php

namespace App\Libraries;

use Normalizer;

final class TextNormalizer
{
    /** Trimmed, NFC-normalized text: the exact string stored and used for error offsets. */
    public static function normalize(string $text): string
    {
        if (preg_match('//u', $text) !== 1) {
            return '';
        }

        $normalized = Normalizer::normalize($text, Normalizer::FORM_C);

        return trim($normalized === false ? $text : $normalized);
    }

    public static function wordCount(string $text): int
    {
        $trimmed = trim($text);

        return $trimmed === '' ? 0 : count(preg_split('/\s+/u', $trimmed) ?: []);
    }
}
