<?php

namespace App\Libraries\Analysis;

use App\Enums\Audience;
use App\Enums\Tone;
use App\Exceptions\AnalysisFailedException;

/**
 * Finds writing issues in Vietnamese text. Implementations may call an external AI service.
 * Output is always passed through {@see ErrorSanitizer}, so implementations need not be perfect.
 */
interface AnalyzerInterface
{
    /**
     * @param string $text NFC-normalized text; offsets must refer to exactly this string
     *
     * @return list<DetectedError>
     *
     * @throws AnalysisFailedException
     */
    public function analyze(string $text, Tone $tone, Audience $audience): array;
}
