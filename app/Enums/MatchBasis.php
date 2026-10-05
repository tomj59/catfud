<?php

namespace App\Enums;

enum MatchBasis: string
{
    case Upc = 'upc';
    case Brand = 'brand';
    case LotCode = 'lot_code';
    case FreeText = 'free_text';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
