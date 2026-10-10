<?php

namespace App\Enums;

enum Tone: string
{
    use EnumValues;

    case Professional = 'professional';
    case Casual       = 'casual';
}
