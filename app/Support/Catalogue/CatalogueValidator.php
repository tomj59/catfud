<?php

namespace App\Support\Catalogue;

use App\Enums\AuditStatus;
use App\Enums\ProductKind;
use App\Support\Gtin;

/**
 * Checks the catalogue files against each other and against the rules, without touching the database. Everything the
 * importer would reject is an error here, so a file that checks clean imports clean.
 */
final class CatalogueValidator
{
    private const KINDS = ['manufacturer', 'brand', 'line', 'subline'];


    /** @var list<array{level:string, file:string, where:string, message:string}> */
    private array $issues = [];

    /** @var array<string,true> "group:slug" */
    private array $tags = [];

    /** @var array<string,array<string,mixed>> lower-case path key => entry (with '_depth', '_names') */
    private array $index = [];

    /** @var array<string,list<string>> ladder slug => lower-case path keys in it */
    private array $byLadder = [];

    public function __construct(private CatalogueFiles $files) {}

    /**
     * @param  list<string>|null  $productSlugs  limit product checks to these files (null = all)
     * @return array{errors:int, warnings:int, issues:list<array{level:string, file:string, where:string, message:string}>}
     */
    public function check(?array $productSlugs = null): array
    {
        $this->issues = [];
        $this->loadVocabulary();
        foreach ($this->files->ladders() as $slug => $path) {
            $this->checkLadder($slug, $this->files->read($path));
        }
        $seen = [];
        $wantedLadders = [];
        foreach ($this->files->products() as $slug => $path) {
            if ($productSlugs !== null && ! in_array($slug, $productSlugs, true)) {
                continue;
            }
            $file = $this->files->read($path);
            $wantedLadders[(string) ($file['ladder'] ?? '')] = true;
            $this->checkProducts($slug, $file, $seen);
        }
        // Checking one brand reports on its own file and its own ladder only. Every ladder is still loaded so paths resolve.
        if ($productSlugs !== null) {
            $this->issues = array_values(array_filter($this->issues, fn ($i) => ! str_starts_with($i['file'], 'ladders/') || isset($wantedLadders[basename($i['file'], '.json')])));
        }

        return [
            'errors' => count(array_filter($this->issues, fn ($i) => $i['level'] === 'error')),
            'warnings' => count(array_filter($this->issues, fn ($i) => $i['level'] === 'warning')),
            'issues' => $this->issues,
        ];
    }

    /** Lower-case path key for a list of names. @param list<string> $names */
    public static function key(array $names): string
    {
        return implode('>', array_map(fn ($n) => mb_strtolower(trim((string) $n)), $names));
    }

    private function add(string $level, string $file, string $where, string $message): void
    {
        $this->issues[] = ['level' => $level, 'file' => $file, 'where' => $where, 'message' => $message];
    }

    private function loadVocabulary(): void
    {
        $this->tags = [];
        $path = $this->files->vocabularyPath();
        if (! is_file($path)) {
            $this->add('error', 'vocabulary.json', '', 'Missing. Run catalogue:export first, or create it.');

            return;
        }
        foreach ($this->files->read($path)['tags'] ?? [] as $i => $t) {
            if (empty($t['group']) || empty($t['slug']) || empty($t['label'])) {
                $this->add('error', 'vocabulary.json', "tags[$i]", 'Needs group, slug and label.');

                continue;
            }
            $this->tags[$t['group'].':'.$t['slug']] = true;
        }
    }

    /** @param array<string,mixed> $file */
    private function checkLadder(string $slug, array $file): void
    {
        $name = "ladders/{$slug}.json";
        $root = $file['ladder'] ?? null;
        if (! is_array($root) || empty($root['name'])) {
            $this->add('error', $name, '', 'Needs a "ladder" object with a name.');

            return;
        }
        if (CatalogueFiles::slug($root['name']) !== $slug) {
            $this->add('warning', $name, $root['name'], "File name should be {$slug}.json for \"{$root['name']}\".");
        }
        if (! ($file['reviewed'] ?? false)) {
            $this->add('warning', $name, '', 'Not reviewed yet ("reviewed": false).');
        }
        $this->byLadder[$slug] = [];
        $this->walk($name, $slug, $root, []);
    }

    /**
     * @param  array<string,mixed>  $entry
     * @param  list<string>  $trail
     */
    private function walk(string $file, string $ladder, array $entry, array $trail): void
    {
        $max = (int) config('catfud.brand_tree_max_depth', 5);
        $names = [...$trail, (string) ($entry['name'] ?? '')];
        $where = implode(' > ', $names);
        $depth = count($names);

        if (trim($names[$depth - 1]) === '') {
            $this->add('error', $file, $where, 'A rung has no name.');

            return;
        }
        if ($depth > $max) {
            $this->add('error', $file, $where, "Deeper than the {$max}-rung limit.");

            return;
        }
        $key = self::key($names);
        if (isset($this->index[$key])) {
            $this->add('error', $file, $where, 'The same ladder path appears twice (also in another file or place).');
        }
        $this->index[$key] = [...$entry, '_depth' => $depth, '_names' => $names, '_ladder' => $ladder];
        $this->byLadder[$ladder][] = $key;

        if (isset($entry['kind']) && $entry['kind'] !== null && ! in_array($entry['kind'], self::KINDS, true)) {
            $this->add('error', $file, $where, 'kind must be one of '.implode(', ', self::KINDS).'.');
        }
        foreach ($entry['tags'] ?? [] as $t) {
            if (! isset($this->tags[$t])) {
                $this->add('error', $file, $where, "Unknown tag \"{$t}\" (not in vocabulary.json).");
            }
        }
        foreach (['aliases', 'species'] as $k) {
            if (isset($entry[$k]) && (! is_array($entry[$k]) || array_filter($entry[$k], fn ($v) => ! is_string($v) || trim($v) === ''))) {
                $this->add('error', $file, $where, "{$k} must be a list of non-empty strings.");
            }
        }
        if (isset($entry['status']) && ! in_array($entry['status'], \App\Enums\NodeStatus::values(), true)) {
            $this->add('error', $file, $where, 'status must be one of '.implode(', ', \App\Enums\NodeStatus::values()).'.');
        }

        // Siblings must be distinguishable: no repeated names, and no alias that is another sibling's name or alias.
        $seen = [];
        foreach ($entry['children'] ?? [] as $child) {
            foreach (array_merge([(string) ($child['name'] ?? '')], $child['aliases'] ?? []) as $label) {
                $l = mb_strtolower(trim($label));
                $sameOwner = isset($seen[$l]) && mb_strtolower($seen[$l]) === mb_strtolower((string) ($child['name'] ?? ''));
                if ($l !== '' && isset($seen[$l]) && $sameOwner && $label === ($child['name'] ?? '')) {
                    $this->add('error', $file, $where, "\"{$label}\" is listed twice.");
                } elseif ($l !== '' && isset($seen[$l]) && ! $sameOwner) {
                    $this->add('error', $file, $where, "\"{$label}\" is claimed by both \"{$seen[$l]}\" and \"{$child['name']}\".");
                } elseif ($l !== '' && isset($seen[$l]) && $label === ($child['name'] ?? '')) {
                    $this->add('error', $file, $where, "\"{$label}\" is listed twice.");
                }
                $seen[$l] ??= (string) ($child['name'] ?? '');
            }
        }
        foreach ($entry['children'] ?? [] as $child) {
            $this->walk($file, $ladder, $child, $names);
        }
    }

    /**
     * @param  array<string,mixed>  $file
     * @param  array<string,string>  $seen  gtin/import_key => where first seen
     */
    private function checkProducts(string $slug, array $file, array &$seen): void
    {
        $name = "products/{$slug}.json";
        $ladder = $file['ladder'] ?? null;
        if (! $ladder || ! isset($this->byLadder[$ladder])) {
            $this->add('error', $name, '', 'Needs "ladder": the slug of an existing ladder file.');
        }
        if (! ($file['reviewed'] ?? false)) {
            $this->add('warning', $name, '', 'Not reviewed yet ("reviewed": false). Import refuses it unless told to allow that.');
        }
        foreach ($file['products'] ?? [] as $i => $p) {
            $where = '#'.($i + 1).' '.($p['name'] ?? '?');
            $this->checkProduct($name, $ladder, $where, $p, $seen);
        }
    }

    /**
     * @param  array<string,mixed>  $p
     * @param  array<string,string>  $seen
     */
    private function checkProduct(string $file, ?string $ladder, string $where, array $p, array &$seen): void
    {
        if (trim((string) ($p['name'] ?? '')) === '') {
            $this->add('error', $file, $where, 'Needs a name.');
        }
        $path = $p['path'] ?? null;
        $node = null;
        if (! is_array($path) || ! $path) {
            $this->add('error', $file, $where, 'Needs "path": the ladder as a list of names, e.g. ["Hill\'s", "Science Diet", "Adult"].');
        } else {
            $node = $this->index[self::key($path)] ?? null;
            if (! $node) {
                $this->add('error', $file, $where, 'Path does not exist in any ladder file: '.implode(' > ', $path).'. Add the rung to the ladder first.');
            } else {
                if ($ladder && $node['_ladder'] !== $ladder) {
                    $this->add('error', $file, $where, "Path belongs to ladders/{$node['_ladder']}.json, not {$ladder}.json.");
                }
                if ($node['_names'] !== array_values($path)) {
                    $this->add('warning', $file, $where, 'Path differs from the ladder only in capitalisation or spacing. Use: '.implode(' > ', $node['_names']));
                }
                if (! empty($node['children'])) {
                    $this->add('warning', $file, $where, 'Placed on a rung that has sub-lines ('.implode(' > ', $node['_names']).'). Is there a more specific rung?');
                }
            }
        }

        $gtin = $p['gtin'] ?? null;
        if ($gtin !== null && Gtin::normalize((string) $gtin) === null) {
            $this->add('error', $file, $where, "gtin \"{$gtin}\" is not a valid 8/12/13/14-digit barcode.");
        }
        $ids = array_filter([$gtin !== null ? 'gtin:'.(Gtin::normalize((string) $gtin) ?? $gtin) : null, ! empty($p['import_key']) ? 'key:'.$p['import_key'] : null]);
        if (! $ids) {
            $this->add('error', $file, $where, 'Needs a gtin, or an import_key while the barcode is unknown.');
        }
        foreach ($ids as $id) {
            if (isset($seen[$id])) {
                // A repeated import_key is two rows fighting for one product. A repeated barcode on rows that each have their own
                // import_key is a source conflict: both products are kept, only the first gets the code.
                if (str_starts_with($id, 'gtin:') && ! empty($p['import_key'])) {
                    $this->add('warning', $file, $where, "Barcode {$id} is also on {$seen[$id]}; only the first product will get it.");
                } else {
                    $this->add('error', $file, $where, "Duplicate {$id} (also {$seen[$id]}).");
                }
            }
            $seen[$id] ??= "{$file} {$where}";
        }
        foreach ($p['barcodes'] ?? [] as $b) {
            $code = Gtin::normalize((string) ($b['gtin'] ?? ''));
            $raw = preg_replace('/[\s\-]/', '', (string) ($b['gtin'] ?? ''));
            if ($code === null && preg_match('/^[1-9]\d{13}$/', (string) $raw) && Gtin::hasValidCheckDigit($raw)) {
                $this->add('warning', $file, $where, "Case-level GTIN-14 {$raw}: kept in the product's meta, not stored as a scannable barcode.");
            } elseif ($code === null) {
                $this->add('error', $file, $where, 'barcodes: "'.($b['gtin'] ?? '').'" is not a valid 8/12/13/14-digit barcode.');
            } elseif ($code !== Gtin::normalize((string) $gtin) && isset($seen['gtin:'.$code]) && ($seen['gtin:'.$code] !== "{$file} {$where}")) {
                $this->add('warning', $file, $where, "Pack barcode {$code} is also on {$seen['gtin:'.$code]}; it will not be attached twice.");
            } else {
                $seen['gtin:'.$code] ??= "{$file} {$where}";
            }
        }

        if (isset($p['kind']) && ! in_array($p['kind'], ProductKind::values(), true)) {
            $this->add('error', $file, $where, 'kind must be one of '.implode(', ', ProductKind::values()).'.');
        }
        if (isset($p['audit_status']) && ! in_array($p['audit_status'], AuditStatus::values(), true)) {
            $this->add('error', $file, $where, 'audit_status must be one of '.implode(', ', AuditStatus::values()).'.');
        }
        if (! empty($p['image_url']) && ! filter_var($p['image_url'], FILTER_VALIDATE_URL)) {
            $this->add('error', $file, $where, 'image_url is not a URL.');
        }
        $tags = $p['tags'] ?? [];
        foreach ($tags as $t) {
            if (! isset($this->tags[$t])) {
                $this->add('error', $file, $where, "Unknown tag \"{$t}\" (not in vocabulary.json).");
            }
        }
        if (count($tags) !== count(array_unique($tags))) {
            $this->add('warning', $file, $where, 'A tag is listed twice.');
        }
        if ($node) {
            $inherited = [];
            foreach ($this->chain($node['_names']) as $e) {
                $inherited = [...$inherited, ...($e['tags'] ?? [])];
            }
            foreach (array_diff($inherited, $tags) as $missing) {
                $this->add('warning', $file, $where, "Missing \"{$missing}\", which its ladder gives every product under it.");
            }
            $species = [];
            foreach ($this->chain($node['_names']) as $e) {
                $species = $e['species'] ?? $species;
            }
            if ($species && isset($p['species']) && ! in_array(strtolower($p['species']), array_map('strtolower', $species), true)) {
                $this->add('warning', $file, $where, "Species \"{$p['species']}\" but the ladder says ".implode('/', $species).'.');
            }
        }
        if (! empty($p['_flags'])) {
            $this->add('warning', $file, $where, 'Open flags: '.implode(' | ', $p['_flags']));
        }
    }

    /** @param list<string> $names @return list<array<string,mixed>> entries from the root down to the last name */
    private function chain(array $names): array
    {
        $out = [];
        for ($i = 1; $i <= count($names); $i++) {
            if ($e = $this->index[self::key(array_slice($names, 0, $i))] ?? null) {
                $out[] = $e;
            }
        }

        return $out;
    }
}
