<?php

namespace App\Libraries\Analysis;

/** One issue reported by an analyzer. Offsets are UTF-16 code units into the analyzed text, end exclusive. */
final class DetectedError
{
    public function __construct(
        public readonly string $category,
        public readonly int $startIndex,
        public readonly int $endIndex,
        public readonly string $original,
        public readonly string $suggestion,
        public readonly string $explanation,
    ) {
    }
}
