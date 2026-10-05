<?php

namespace App\Enums;

enum RatingValue: string
{
    case Liked = 'liked';
    case Neutral = 'neutral';
    case Refused = 'refused';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
