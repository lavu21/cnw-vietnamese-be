<?php

namespace App\Validation;

/** Custom validation rules for writing submissions. */
class WritingRules
{
    public function min_words(?string $str, string $params): bool
    {
        return \App\Libraries\TextNormalizer::wordCount((string) $str) >= (int) $params;
    }
}
