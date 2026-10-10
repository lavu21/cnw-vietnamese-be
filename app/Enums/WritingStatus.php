<?php

namespace App\Enums;

enum WritingStatus: string
{
    use EnumValues;

    case Processing = 'processing';
    case Completed  = 'completed';
    case Failed     = 'failed';
}
