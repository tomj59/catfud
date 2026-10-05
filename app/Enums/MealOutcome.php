<?php

namespace App\Enums;

enum MealOutcome: string
{
    case AteAll = 'ate_all';
    case AteSome = 'ate_some';
    case Refused = 'refused';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
