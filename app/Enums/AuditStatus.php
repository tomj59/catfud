<?php

namespace App\Enums;

enum AuditStatus: string
{
    case Unreviewed = 'unreviewed';
    case Reviewed = 'reviewed';
    case NeedsChanges = 'needs_changes';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
