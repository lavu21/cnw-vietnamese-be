<?php

namespace App\Enums;

trait EnumValues
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public static function inList(): string
    {
        return 'in_list[' . implode(',', self::values()) . ']';
    }
}
