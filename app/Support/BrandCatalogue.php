<?php

namespace App\Support;

/**
 * The curated brand ladder for a region (database/seeds/brand_suggestions_{region}.json): who makes what, which lines a
 * brand sells, which species they are for. It feeds the brand picker before any product exists under a name, and
 * `php artisan catalogue:seed-brands` turns it into real tree nodes. Array order is display order.
 *
 * Entry: {name, kind?, aliases?, tags?, species?, verify?, notes?, children?[]}
 */
final class BrandCatalogue
{
    /** @return list<array<string,mixed>> */
    public static function tree(?string $region = null): array
    {
        $file = database_path('seeds/brand_suggestions_'.strtolower($region ?? Region::current()).'.json');

        return is_file($file) ? (json_decode(file_get_contents($file), true)['tree'] ?? []) : [];
    }

    /**
     * The entries that can follow a path of names ([] = the top level), in file order.
     *
     * @param  list<string>  $names
     * @return list<array<string,mixed>>
     */
    public static function childrenOf(array $names, ?string $region = null): array
    {
        $level = self::tree($region);
        foreach ($names as $name) {
            $hit = null;
            foreach ($level as $entry) {
                if (self::matches($entry, $name)) {
                    $hit = $entry;
                    break;
                }
            }
            if (! $hit) {
                return [];
            }
            $level = $hit['children'] ?? [];
        }

        return $level;
    }

    /** True when $name is the entry's name or one of its aliases (case-insensitive). */
    public static function matches(array $entry, string $name): bool
    {
        $name = mb_strtolower(trim($name));

        return mb_strtolower($entry['name']) === $name
            || in_array($name, array_map('mb_strtolower', $entry['aliases'] ?? []), true);
    }

    /**
     * Every entry with the full chain of entries above it, parents first.
     *
     * @return list<list<array<string,mixed>>>
     */
    public static function chains(?string $region = null): array
    {
        $out = [];
        $walk = function (array $entries, array $trail) use (&$walk, &$out) {
            foreach ($entries as $e) {
                $chain = [...$trail, $e];
                $out[] = $chain;
                $walk($e['children'] ?? [], $chain);
            }
        };
        $walk(self::tree($region), []);

        return $out;
    }

    /** Whether something sold for $species (null = species unknown, show everything). */
    public static function forSpecies(?array $nodeSpecies, ?string $species): bool
    {
        return ! $species || ! $nodeSpecies || in_array(strtolower($species), array_map('strtolower', $nodeSpecies), true);
    }
}
