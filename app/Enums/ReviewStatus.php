<?php

namespace App\Enums;

enum ReviewStatus: string
{
    case New = 'new';
    case Confirmed = 'confirmed';
    case Dismissed = 'dismissed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
