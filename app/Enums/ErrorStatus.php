<?php

namespace App\Enums;

enum ErrorStatus: string
{
    use EnumValues;

    case Pending  = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
