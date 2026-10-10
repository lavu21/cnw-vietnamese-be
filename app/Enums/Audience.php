<?php

namespace App\Enums;

enum Audience: string
{
    use EnumValues;

    case Adult       = 'adult';
    case Child       = 'child';
    case Colleague   = 'colleague';
    case CloseFriend = 'close-friend';
}
