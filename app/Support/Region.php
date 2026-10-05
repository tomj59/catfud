<?php

namespace App\Support;

/**
 * The region forks the whole catalogue: brand tree, products and barcodes are completely separate data sets per
 * region (a regional product can have a different name, recipe and nutrition, so it is a different product).
 * Only "US" exists for now and is never shown in the UI. Every region-aware model is scoped to Region::current(),
 * which an authenticated API request sets from the user's region.
 */
final class Region
{
    public const DEFAULT = 'US';

    private static ?string $current = null;

    public static function current(): string
    {
        return self::$current ?? config('catfud.default_region', self::DEFAULT);
    }

    public static function set(?string $region): void
    {
        self::$current = $region ? strtoupper($region) : null;
    }

    public static function reset(): void
    {
        self::$current = null;
    }

    /** Run a callback inside another region, then restore the previous one. */
    public static function using(string $region, callable $fn): mixed
    {
        $previous = self::$current;
        self::set($region);
        try {
            return $fn();
        } finally {
            self::$current = $previous;
        }
    }
}
