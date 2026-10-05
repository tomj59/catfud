<?php

namespace App\Enums;

enum ProductKind: string
{
    case Food = 'food';
    case Treat = 'treat';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
