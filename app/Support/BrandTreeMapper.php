<?php

namespace App\Support;

use App\Models\BrandNode;
use App\Models\Product;
use App\Models\Tag;

/**
 * Places products in the brand tree and keeps the display caches (brand, line, path_text, search_text) and the
 * texture / medium / diet tags in step. The rules file only needs entries where the flat spreadsheet columns hide a
 * real ladder (a manufacturer above the brand, a sub-line in the variety column, a clinical diet); every other
 * product simply becomes brand > line.
 */
class BrandTreeMapper
{
    /** @var list<array<string,mixed>> */
    private array $rules;

    public function __construct(?string $rulesFile = null)
    {
        $file = $rulesFile ?? database_path('seeds/brand_map_us.json');
        $this->rules = is_file($file) ? (json_decode((string) file_get_contents($file), true)['rules'] ?? []) : [];
    }

    /** "After Dark Line" -> "After Dark"; 'Creamy Delights ("with a touch of real milk")' -> "Creamy Delights". */
    public static function cleanLine(?string $line): ?string
    {
        if ($line === null) {
            return null;
        }
        $line = preg_replace('/\s*\([^)]*\)/u', '', $line);
        $line = trim(preg_replace('/\s+Line$/i', '', trim($line)), " \t\"'");

        return $line === '' ? null : $line;
    }

    /**
     * @return array{segments:list<array<string,mixed>>, tags:list<string>}
     */
    public function pathFor(string $brand, ?string $line, ?string $variety = null): array
    {
        $line = self::cleanLine($line);
        $rule = $this->ruleFor($brand, $line);

        $segments = $rule['prefix'] ?? [['name' => $brand, 'kind' => 'brand']];
        if ($line !== null && ($rule['use_line'] ?? true)) {
            $segments[] = ['name' => $line, 'kind' => 'line'];
        }
        if ($variety !== null && trim($variety) !== '' && ($rule['variety_as'] ?? null)) {
            $segments[] = ['name' => trim($variety), 'kind' => $rule['variety_as']];
        }

        return ['segments' => $segments, 'tags' => []];
    }

    /** @return array<string,mixed>|null */
    private function ruleFor(string $brand, ?string $line): ?array
    {
        $best = null;
        foreach ($this->rules as $r) {
            if (mb_strtolower($r['brand']) !== mb_strtolower($brand)) {
                continue;
            }
            if (array_key_exists('line', $r)) {
                if (mb_strtolower((string) self::cleanLine($r['line'])) !== mb_strtolower((string) $line)) {
                    continue;
                }
                return $r;            // an exact line match wins
            }
            $best ??= $r;             // otherwise the brand-wide rule
        }

        return $best;
    }

    /** Place one product in the tree from its source brand/line/variety, and refresh caches and tags. */
    public function apply(Product $product): void
    {
        $meta = $product->meta ?? [];
        if (! empty($meta['path_locked'])) {
            return; // a person chose this product's path during the audit; imports and rebuilds leave it alone
        }
        $meta['source_brand'] ??= $product->brand;
        $meta['source_line'] ??= $product->line;
        $product->forceFill(['meta' => $meta]);

        ['segments' => $segments] = $this->pathFor($meta['source_brand'], $meta['source_line'], $meta['variety'] ?? null);

        $this->place($product, BrandNode::ensurePath($segments));
    }

    /** Put a product at a node: refresh the display caches and add the texture, medium and node-default tags. */
    public function place(Product $product, BrandNode $node): void
    {
        $chain = $node->ancestry();

        // Prefer an explicit "brand" node. With no kinds on the path, assume manufacturer > brand > line when it is three
        // or more deep, and brand > line when it is shorter.
        $brandNode = collect($chain)->first(fn ($n) => $n->kind === 'brand')
            ?? ($chain[0]->kind === 'manufacturer' && isset($chain[1]) ? $chain[1] : null)
            ?? (count($chain) >= 3 && $chain[0]->kind === null ? $chain[1] : null)
            ?? $chain[0];
        $below = collect($chain)->filter(fn ($n) => $n->depth > $brandNode->depth)->pluck('name')->all();

        $names = collect($chain)->pluck('name');
        $aliases = collect($chain)->flatMap(fn ($n) => $n->aliases ?? []);

        $product->forceFill([
            'brand_node_id' => $node->id,
            'brand' => $brandNode->name,
            'line' => $below ? implode(' › ', $below) : null,
            'path_text' => $names->implode(' › '),
            'search_text' => mb_strtolower($names->merge($aliases)->implode(' ')),
        ])->save();

        $this->attachTags($product, array_merge(
            TextureParser::tagKeys($product->texture),
            collect($chain)->flatMap(fn ($n) => $n->default_tags ?? [])->all(),
        ));
    }

    /** @param list<string> $keys "group:slug" */
    public function attachTags(Product $product, array $keys): void
    {
        $ids = [];
        foreach (array_unique($keys) as $key) {
            [$group, $slug] = array_pad(explode(':', $key, 2), 2, null);
            if ($tag = Tag::where('group', $group)->where('slug', $slug)->first()) {
                $ids[] = $tag->id;
            }
        }
        if ($ids) {
            $product->tags()->syncWithoutDetaching($ids);
            $product->unsetRelation('tags');
        }
    }
}
