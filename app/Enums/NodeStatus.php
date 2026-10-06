<?php

namespace App\Enums;

/** Lifecycle of a brand-ladder rung. */
enum NodeStatus: string
{
    case Active = 'active';
    case Retiring = 'retiring';
    case Retired = 'retired';
    case Disabled = 'disabled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Statuses that stop a rung being offered for new products. */
    public function hidesFromUsers(): bool
    {
        return $this === self::Retired || $this === self::Disabled;
    }

    /** @return list<string> */
    public static function hiddenValues(): array
    {
        return array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => $s->hidesFromUsers()));
    }
}
