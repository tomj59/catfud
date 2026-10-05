<?php

namespace App\Enums;

enum MatchConfidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
