<?php

namespace App\Enums;

enum ErrorCategory: string
{
    use EnumValues;

    case Grammar   = 'grammar';
    case Vocab     = 'vocab';
    case Coherence = 'coherence';
}
