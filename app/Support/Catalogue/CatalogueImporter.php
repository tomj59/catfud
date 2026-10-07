<?php

namespace App\Support\Catalogue;

use App\Enums\AuditStatus;
use App\Models\BrandNode;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Scopes\VisibleScope;
use App\Models\Tag;
use App\Support\BrandTreeMapper;
use App\Support\Gtin;
use App\Support\ImageMirror;
use App\Support\Region;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads the catalogue files into the database. Deliberately dumb: a ladder file creates exactly the rungs it lists, and a
 * product file places each product on exactly the path it names with exactly the tags it lists. Nothing is parsed, guessed
 * or inferred. Run CatalogueValidator first; this class refuses anything the validator calls an error.
 */
final class CatalogueImporter
{
    public function __construct(private CatalogueFiles $files, private CatalogueValidator $validator, private BrandTreeMapper $mapper, private ImageMirror $images) {}

    /**
     * @param  list<string>|null  $ladderSlugs  null = every ladder file
     * @return array{nodes_created:int, nodes_updated:int}
     */
    public function importLadders(?array $ladderSlugs = null, bool $overwrite = false, bool $allowUnreviewed = false, bool $dry = false): array
    {
        Region::set($this->files->region);
        $this->vocabulary($dry);
        $out = ['nodes_created' => 0, 'nodes_updated' => 0];
        $pending = [];

        foreach ($this->files->ladders() as $slug => $path) {
            if ($ladderSlugs !== null && ! in_array($slug, $ladderSlugs, true)) {
                continue;
            }
            $file = $this->files->read($path);
            $this->requireReviewed("ladders/{$slug}.json", $file, $allowUnreviewed);
            DB::transaction(function () use ($file, $overwrite, $dry, &$out, &$pending) {
                $this->node($file['ladder'], null, [], $overwrite, $dry, $out, $pending);
            });
        }
        // Successors point at other rungs, so they are resolved once every rung exists.
        foreach ($pending as [$node, $path]) {
            if (! $dry && ($target = $this->find($path))) {
                $node->forceFill(['successor_id' => $target->id])->save();
            }
        }

        return $out;
    }

    /**
     * @return array{created:int, updated:int, unchanged:int, skipped:int, conflicts:list<string>}
     */
    public function importProducts(string $slug, bool $allowUnreviewed = false, bool $dry = false): array
    {
        Region::set($this->files->region);
        $path = $this->files->productsPath($slug);
        if (! is_file($path)) {
            throw new RuntimeException("No product file: products/{$slug}.json");
        }
        $file = $this->files->read($path);
        $this->requireReviewed("products/{$slug}.json", $file, $allowUnreviewed);
        $this->importLadders([(string) ($file['ladder'] ?? '')], false, $allowUnreviewed, $dry);   // its ladder must exist first

        $out = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'conflicts' => []];
        DB::transaction(function () use ($file, $dry, &$out) {
            foreach ($file['products'] ?? [] as $row) {
                $this->product($row, $dry, $out);
            }
        });

        return $out;
    }

    /** @param array<string,mixed> $file */
    private function requireReviewed(string $name, array $file, bool $allow): void
    {
        if (! $allow && ! ($file['reviewed'] ?? false)) {
            throw new RuntimeException("{$name} is not marked \"reviewed\": true. Review it, or pass --allow-unreviewed.");
        }
    }

    private function vocabulary(bool $dry): void
    {
        if ($dry || ! is_file($this->files->vocabularyPath())) {
            return;
        }
        foreach ($this->files->read($this->files->vocabularyPath())['tags'] ?? [] as $t) {
            Tag::updateOrCreate(['group' => $t['group'], 'slug' => $t['slug']], ['label' => $t['label'], 'sort' => $t['sort'] ?? 0]);
        }
    }

    /**
     * @param  array<string,mixed>  $entry
     * @param  list<string>  $trail
     * @param  array{nodes_created:int, nodes_updated:int}  $out
     * @param  list<array{0:BrandNode,1:list<string>}>  $pending
     */
    private function node(array $entry, ?BrandNode $parent, array $trail, bool $overwrite, bool $dry, array &$out, array &$pending): void
    {
        $names = [...$trail, $entry['name']];
        $key = mb_strtolower(Region::current()).'|'.CatalogueValidator::key($names);
        $values = [
            'kind' => $entry['kind'] ?? null,
            'aliases' => $entry['aliases'] ?? null,
            'species' => $entry['species'] ?? null,
            'default_tags' => $entry['tags'] ?? null,
            'notes' => $entry['notes'] ?? null,
            'status' => $entry['status'] ?? 'active',
            'status_on' => $entry['status_on'] ?? null,
            'status_confidence' => $entry['status_confidence'] ?? null,
            'status_source' => $entry['status_source'] ?? null,
            'status_note' => $entry['status_note'] ?? null,
        ];

        $node = BrandNode::where('path_key', $key)->first();
        if (! $node) {
            $out['nodes_created']++;
            if (! $dry) {
                $node = BrandNode::create(['parent_id' => $parent?->id, 'name' => $entry['name'], 'depth' => count($names), 'path_key' => $key, ...$values]);
            }
        } else {
            // A rung that already exists keeps whatever it has; the file only fills the blanks, unless told to overwrite.
            $set = [];
            foreach ($values as $k => $v) {
                $current = $node->{$k};
                $empty = $current === null || $current === [] || $current === '' || ($k === 'status' && $current === 'active');
                if ($v !== null && $v !== 'active' && ($overwrite || $empty) && $current != $v) {
                    $set[$k] = $v;
                } elseif ($overwrite && $v === null && $current !== null && $k !== 'status') {
                    $set[$k] = null;
                } elseif ($overwrite && $k === 'status' && $current !== $v) {
                    $set[$k] = $v;
                }
            }
            if ($set) {
                $out['nodes_updated']++;
                if (! $dry) {
                    $node->forceFill($set)->save();
                }
            }
        }
        if ($node && ! empty($entry['successor'])) {
            $pending[] = [$node, (array) $entry['successor']];
        }
        foreach ($entry['children'] ?? [] as $child) {
            $this->node($child, $node, $names, $overwrite, $dry, $out, $pending);
        }
    }

    /** @param list<string> $names */
    private function find(array $names): ?BrandNode
    {
        return BrandNode::where('path_key', mb_strtolower(Region::current()).'|'.CatalogueValidator::key($names))->first();
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array{created:int, updated:int, unchanged:int, skipped:int, conflicts:list<string>}  $out
     */
    private function product(array $row, bool $dry, array &$out): void
    {
        $node = $this->find($row['path']);
        $gtin = isset($row['gtin']) ? Gtin::normalize((string) $row['gtin']) : null;
        $importKey = $row['import_key'] ?? null;
        if (! $node && ! $dry) {
            throw new RuntimeException('Path not in the ladder: '.implode(' > ', $row['path']).' (run catalogue:check)');
        }

        // A row is identified by its import_key when it has one; the barcode only identifies rows that have no key. Two recipes
        // that a source printed with the same code therefore stay two products (the second one's code is reported, not assigned).
        $scope = fn () => Product::withoutGlobalScope(VisibleScope::class);
        $existing = $importKey ? $scope()->where('import_key', $importKey)->first() : null;
        $existing ??= (! $importKey && $gtin) ? $scope()->where('gtin', $gtin)->first() : null;
        $label = $row['name'].' ('.($importKey ?? $gtin).')';
        $clashed = null;
        if ($gtin) {
            $owner = $scope()->where('gtin', $gtin)->where('gtin_scope', 0)->first();
            $owner ??= ($pack = ProductBarcode::where('gtin', $gtin)->first()) && (! $existing || $pack->product_id !== $existing->id) ? $pack->product : null;
            if ($owner && (! $existing || $owner->id !== $existing->id)) {
                $out['conflicts'][] = "{$label}: barcode {$gtin} already belongs to \"{$owner->name}\"; left unassigned.";
                $clashed = $gtin;
                $gtin = null;
            }
        }

        // The file stops owning a product once a person has edited or reviewed it, or attached a different barcode. A barcode the
        // file itself supplied does not count as a person touching it.
        if ($existing && $importKey && $existing->import_key === $importKey
            && ($existing->last_edited_by !== null || $existing->audit_status !== AuditStatus::Unreviewed || ($existing->gtin !== null && $existing->gtin !== ($gtin ?? $existing->gtin)))) {
            $out['skipped']++;

            return;
        }
        if ($dry) {
            $existing ? $out['updated']++ : $out['created']++;

            return;
        }

        // Case / inner-pack GTIN-14s are not scannable unit codes; they live on the product (meta.case_gtins), not in the barcode table.
        $cases = [];
        foreach ($row['barcodes'] ?? [] as $b) {
            $raw = (string) preg_replace('/[\s\-]/', '', (string) ($b['gtin'] ?? ''));
            if (! Gtin::normalize($raw) && preg_match('/^[1-9]\d{13}$/', $raw) && Gtin::hasValidCheckDigit($raw)) {
                $cases[$raw] = $b['pack_label'] ?? null;
            }
        }
        $meta = $row['meta'] ?? null;
        if ($cases) {
            $meta = [...($meta ?? []), 'case_gtins' => $cases];
        }

        $attributes = [
            'name' => $row['name'], 'title_as_listed' => $row['title_as_listed'] ?? null, 'species' => $row['species'] ?? 'cat',
            'kind' => $row['kind'] ?? 'food', 'form' => $row['form'] ?? null, 'texture' => $row['texture'] ?? null,
            'description' => $row['description'] ?? null, 'ingredients' => $row['ingredients'] ?? null, 'nutrition' => $row['nutrition'] ?? null,
            'source_url' => $row['source_url'] ?? null, 'source' => $row['source'] ?? 'catalogue',
            'import_key' => $importKey, 'meta' => $meta, 'audit_notes' => $row['audit_notes'] ?? null,
            'last_verified_at' => $row['last_verified_at'] ?? null,
        ];
        // An outside picture is registered with the image mirror (which keys it and sets image_url to our own copy); anything
        // else in image_url is stored as given.
        $image = $row['image_url'] ?? null;
        $mirrored = ImageMirror::isRemote($image);
        if (! $mirrored) {
            $attributes['image_url'] = $image;
        }
        $changed = ! $existing;
        if ($existing) {
            $existing->update($gtin ? [...$attributes, 'gtin' => $gtin] : $attributes);
            $product = $existing;
            $changed = $product->wasChanged();
        } else {
            $product = Product::create([...$attributes, 'brand' => '', 'gtin' => $gtin, 'moderation_status' => 'approved',
                'audit_status' => $row['audit_status'] ?? AuditStatus::Unreviewed->value]);
        }

        $this->mapper->cachePlacement($product, $node);
        $changed = $changed || $product->wasChanged();
        if ($mirrored) {
            $changed = $this->images->register($product, $image, $row['source_url'] ?? null) || $changed;
        }
        $changed = $this->setTags($product, $row['tags'] ?? []) || $changed;
        foreach ($row['barcodes'] ?? [] as $b) {
            $code = Gtin::normalize((string) ($b['gtin'] ?? ''));
            if (! $code || $code === $product->gtin || $code === $clashed) {
                continue;
            }
            $holder = Product::withoutGlobalScope(VisibleScope::class)->where('gtin', $code)->where('id', '!=', $product->id)->first();
            if ($holder) {
                $out['conflicts'][] = "{$label}: barcode {$code} already belongs to \"{$holder->name}\"; not attached.";

                continue;
            }
            $bc = ProductBarcode::firstOrCreate(['gtin' => $code], ['product_id' => $product->id, 'pack_label' => $b['pack_label'] ?? null]);
            if ($bc->product_id !== $product->id) {
                $out['conflicts'][] = "{$label}: barcode {$code} is already a pack barcode of another product; not attached.";
            }
            $changed = $bc->wasRecentlyCreated || $changed;
        }
        $out[$existing ? ($changed ? 'updated' : 'unchanged') : 'created']++;
    }

    /** The product ends up with exactly these tags. True when that changed anything. @param list<string> $keys "group:slug" */
    private function setTags(Product $product, array $keys): bool
    {
        $ids = [];
        foreach ($keys as $key) {
            [$group, $slug] = array_pad(explode(':', $key, 2), 2, null);
            $tag = Tag::where('group', $group)->where('slug', $slug)->first() ?? throw new RuntimeException("Unknown tag {$key}");
            $ids[] = $tag->id;
        }
        $result = $product->tags()->sync($ids);
        $product->unsetRelation('tags');

        return (bool) ($result['attached'] || $result['detached']);
    }
}
