<?php

namespace App\Support;

/** Parses "Purina > Pro Plan > Prime Plus" (either > or ›) into ladder segments. */
class LadderPath
{
    /** @return list<array{name:string}>|null|false null: nothing typed; false: deeper than the ladder cap */
    public static function parse(?string $path): array|null|false
    {
        if ($path === null || trim($path) === '') {
            return null;
        }
        $segments = array_values(array_filter(
            array_map(fn ($s) => ['name' => trim(preg_replace('/\s+/', ' ', $s))], preg_split('/\s*(?:>|›)\s*/u', $path)),
            fn ($s) => $s['name'] !== ''
        ));

        return count($segments) > (int) config('catfud.brand_tree_max_depth', 5) ? false : ($segments ?: null);
    }
}
