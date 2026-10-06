<?php

namespace App\Support;

use App\Models\BrandNode;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Renaming, moving and merging brand-tree nodes. Manufacturers rebrand, lines get renamed, retailers misspell: the tree
 * has to absorb that without losing any product, barcode or rating. A rename keeps the old name as an alias; a merge
 * folds one node (and everything under it) into another and keeps its names as aliases; products always follow.
 */
class BrandNodeEditor
{
    public function __construct(private BrandTreeMapper $mapper) {}

    /**
     * @param  array{name?:string, parent_id?:?int, kind?:?string, aliases?:?list<string>, species?:?list<string>, notes?:?string}  $changes
     */
    public function update(BrandNode $node, array $changes): BrandNode
    {
        return DB::transaction(function () use ($node, $changes) {
            $newName = array_key_exists('name', $changes) ? trim(preg_replace('/\s+/', ' ', (string) $changes['name'])) : $node->name;
            if ($newName === '') {
                throw new RuntimeException('A name cannot be empty.');
            }

            $moving = array_key_exists('parent_id', $changes) && ($changes['parent_id'] ?? null) !== $node->parent_id;
            $parent = $moving ? ($changes['parent_id'] ? BrandNode::find($changes['parent_id']) : null) : $node->parent;
            if ($moving && $changes['parent_id'] && ! $parent) {
                throw new RuntimeException('That parent does not exist.');
            }
            if ($parent && ($parent->id === $node->id || str_starts_with($parent->path_key.'>', $node->path_key.'>'))) {
                throw new RuntimeException('A node cannot be moved under itself.');
            }

            if ($newName !== $node->name || $moving) {
                $clash = BrandNode::where('parent_id', $parent?->id)->where('id', '!=', $node->id)->get()
                    ->first(fn (BrandNode $n) => $n->matchesName($newName));
                if ($clash) {
                    throw new RuntimeException("\"{$clash->name}\" already exists there. Merge the two instead.");
                }
                $this->assertFits($node, ($parent?->depth ?? 0) + 1);
            }

            $aliases = collect(array_key_exists('aliases', $changes) ? ($changes['aliases'] ?? []) : ($node->aliases ?? []));
            if ($newName !== $node->name) {
                $aliases->push($node->name);   // the old name keeps working
            }
            $aliases = $aliases->map(fn ($a) => trim((string) $a))->filter()
                ->reject(fn ($a) => mb_strtolower($a) === mb_strtolower($newName))->unique(fn ($a) => mb_strtolower($a))->values()->all();

            $node->forceFill([
                'kind' => array_key_exists('kind', $changes) ? $changes['kind'] : $node->kind,
                'species' => array_key_exists('species', $changes) ? ($changes['species'] ?: null) : $node->species,
                'notes' => array_key_exists('notes', $changes) ? $changes['notes'] : $node->notes,
                'aliases' => $aliases ?: null,
            ]);

            $ids = $this->relocate($node, $parent, $newName);
            $this->refreshProducts($ids);

            return $node->fresh();
        });
    }

    /** Fold $source (and all it holds) into $target. The source's name and aliases become aliases of the target. */
    public function merge(BrandNode $source, BrandNode $target): BrandNode
    {
        return DB::transaction(function () use ($source, $target) {
            if ($source->id === $target->id) {
                throw new RuntimeException('Pick a different node to merge into.');
            }
            if (str_starts_with($target->path_key.'>', $source->path_key.'>')) {
                throw new RuntimeException('A node cannot be merged into something under it.');
            }
            $this->assertFits($source, $target->depth + 1, childrenOnly: true);

            $touched = [];
            $this->mergeInto($source, $target, $touched);
            $this->refreshProducts(array_values(array_unique($touched)));

            return $target->fresh();
        });
    }

    /** @param list<int> $touched */
    private function mergeInto(BrandNode $source, BrandNode $target, array &$touched): void
    {
        $aliases = collect($target->aliases ?? [])->push($source->name)->merge($source->aliases ?? [])
            ->map(fn ($a) => trim((string) $a))->filter()->reject(fn ($a) => mb_strtolower($a) === mb_strtolower($target->name))
            ->unique(fn ($a) => mb_strtolower($a))->values()->all();
        $target->forceFill([
            'aliases' => $aliases ?: null,
            'species' => $target->species ?: $source->species,
            'default_tags' => $target->default_tags ?: $source->default_tags,
            'kind' => $target->kind ?: $source->kind,
        ])->save();

        Product::where('brand_node_id', $source->id)->update(['brand_node_id' => $target->id]);
        $touched[] = $target->id;

        foreach (BrandNode::where('parent_id', $source->id)->get() as $child) {
            $twin = BrandNode::where('parent_id', $target->id)->get()->first(fn (BrandNode $n) => $n->matchesName($child->name));
            if ($twin) {
                $this->mergeInto($child, $twin, $touched);
            } else {
                $touched = [...$touched, ...$this->relocate($child, $target, $child->name)];
            }
        }
        $source->delete();
    }

    /** Throws when this node's subtree, placed at $newDepth, would be deeper than the ladder cap. */
    private function assertFits(BrandNode $node, int $newDepth, bool $childrenOnly = false): void
    {
        $max = (int) config('catfud.brand_tree_max_depth', 5);
        $deepest = BrandNode::where(fn ($q) => $q->where('path_key', $node->path_key)
            ->orWhereRaw("path_key like ? escape '!'", [self::likePrefix($node->path_key.'>')]))->max('depth') ?? $node->depth;
        // Levels below the node; when only its children move (a merge), they land at $newDepth, one below the node's own level.
        $span = $childrenOnly ? max($deepest - $node->depth - 1, 0) : $deepest - $node->depth;
        if ($newDepth + $span > $max) {
            throw new RuntimeException("That would make the ladder deeper than {$max} levels.");
        }
    }

    /**
     * Give $node a new parent and name, rewriting depth and path_key down the whole subtree.
     *
     * @return list<int> ids of every node touched
     */
    private function relocate(BrandNode $node, ?BrandNode $parent, string $name): array
    {
        $oldKey = $node->path_key;
        $oldDepth = $node->depth;
        $newKey = ($parent ? $parent->path_key.'>' : mb_strtolower($node->region).'|').mb_strtolower($name);
        $newDepth = ($parent?->depth ?? 0) + 1;
        $ids = [$node->id];

        $descendants = BrandNode::whereRaw("path_key like ? escape '!'", [self::likePrefix($oldKey.'>')])->orderBy('depth')->get();
        $node->forceFill(['parent_id' => $parent?->id, 'name' => $name, 'path_key' => $newKey, 'depth' => $newDepth])->save();
        foreach ($descendants as $d) {
            $d->forceFill(['path_key' => $newKey.substr($d->path_key, strlen($oldKey)), 'depth' => $d->depth + ($newDepth - $oldDepth)])->save();
            $ids[] = $d->id;
        }

        return $ids;
    }

    private static function likePrefix(string $prefix): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix).'%';
    }

    /** @param list<int> $nodeIds */
    private function refreshProducts(array $nodeIds): void
    {
        if (! $nodeIds) {
            return;
        }
        Product::whereIn('brand_node_id', $nodeIds)->each(function (Product $p) {
            $this->mapper->place($p, BrandNode::findOrFail($p->brand_node_id));
        });
    }
}
