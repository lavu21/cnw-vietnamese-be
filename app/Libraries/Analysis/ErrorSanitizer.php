<?php

namespace App\Libraries\Analysis;

use App\Enums\ErrorCategory;
use App\Libraries\Utf16;

/**
 * Validates analyzer output (it may come from an AI service) before it is stored:
 * drops issues with unknown categories, out-of-range offsets, a mismatching `original`
 * or an overlap with an earlier issue, and returns the rest sorted by `startIndex`.
 */
final class ErrorSanitizer
{
    /**
     * @param list<DetectedError> $errors
     *
     * @return list<DetectedError>
     */
    public function sanitize(string $text, array $errors): array
    {
        $length = Utf16::length($text);
        $valid  = [];

        foreach ($errors as $error) {
            if (ErrorCategory::tryFrom($error->category) === null
                || $error->startIndex < 0
                || $error->endIndex <= $error->startIndex
                || $error->endIndex > $length
                || trim($error->suggestion) === ''
                || trim($error->explanation) === ''
                || Utf16::slice($text, $error->startIndex, $error->endIndex) !== $error->original) {
                continue;
            }

            $valid[] = $error;
        }

        usort($valid, static fn (DetectedError $a, DetectedError $b): int => [$a->startIndex, $a->endIndex] <=> [$b->startIndex, $b->endIndex]);

        $kept    = [];
        $lastEnd = 0;

        foreach ($valid as $error) {
            if ($error->startIndex < $lastEnd) {
                continue;
            }

            $kept[]  = $error;
            $lastEnd = $error->endIndex;
        }

        return $kept;
    }
}

