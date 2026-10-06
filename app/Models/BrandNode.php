<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToRegion;
use App\Support\BrandCatalogue;
use App\Support\Region;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * One name in a brand ladder: manufacturer > brand > line > sub-line > sub-sub-line. Depth varies by brand, every
 * level is optional, and the ladder is capped (config catfud.brand_tree_max_depth) so it cannot sprawl.
 */
class BrandNode extends Model
{
    use Auditable, BelongsToRegion;

    protected $fillable = ['region', 'parent_id', 'name', 'kind', 'depth', 'path_key', 'aliases', 'default_tags', 'species', 'notes'];

    protected function casts(): array
    {
        return ['aliases' => 'array', 'default_tags' => 'array', 'species' => 'array'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Names from the root down to this node. @return list<self> */
    public function ancestry(): array
    {
        $chain = [$this];
        $node = $this;
        while ($node->parent_id && ($node = $node->parent)) {
            array_unshift($chain, $node);
        }

        return $chain;
    }

    /**
     * Find or create the node at the end of a path. $segments: list of ['name'=>..., 'kind'=>?, 'aliases'=>?, 'tags'=>?].
     * An existing node's kind/aliases/default tags are only filled in when they are still empty, so repeated imports
     * never override edits made later.
     *
     * @param  list<array{name:string, kind?:?string, aliases?:?list<string>, tags?:?list<string>, species?:?list<string>}>  $segments
     */
    public static function ensurePath(array $segments): self
    {
        $max = (int) config('catfud.brand_tree_max_depth', 5);
        $segments = array_values(array_filter($segments, fn ($s) => trim((string) ($s['name'] ?? '')) !== ''));

        if (! $segments) {
            throw new InvalidArgumentException('A brand path needs at least one name.');
        }
        if (count($segments) > $max) {
            throw new InvalidArgumentException("A brand path can be at most {$max} levels deep.");
        }

        $parent = null;
        $canonical = [];
        foreach ($segments as $i => $seg) {
            $typed = trim(preg_replace('/\s+/', ' ', $seg['name']));

            // Names and aliases resolve to the node that already exists ("Purina Pro Plan" -> "Pro Plan"), so a
            // renamed line or a retailer's spelling never creates a second node.
            $node = self::where('parent_id', $parent?->id)->get()->first(fn (self $n) => $n->matchesName($typed));

            if (! $node) {
                // Not in the tree yet: use the curated ladder's spelling, kind, aliases and species when it knows the name.
                $curated = collect(BrandCatalogue::childrenOf($canonical))->first(fn ($e) => BrandCatalogue::matches($e, $typed));
                $name = $curated['name'] ?? $typed;
                $pathKey = mb_strtolower(Region::current()).'|'.implode('>', [...array_map('mb_strtolower', $canonical), mb_strtolower($name)]);

                // A hand-typed path carries no kinds; borrow one from another node with the same name ("Pro Plan" is a brand).
                $kind = $seg['kind'] ?? ($curated['kind'] ?? null) ?? self::where('name', $name)->whereNotNull('kind')->value('kind');

                $node = self::firstOrCreate(
                    ['path_key' => $pathKey],
                    ['parent_id' => $parent?->id, 'name' => $name, 'kind' => $kind, 'depth' => $i + 1,
                        'aliases' => $seg['aliases'] ?? ($curated['aliases'] ?? null),
                        'default_tags' => $seg['tags'] ?? ($curated['tags'] ?? null),
                        'species' => $seg['species'] ?? ($curated['species'] ?? null)],
                );
            }

            $fill = [];
            if (! $node->kind && ! empty($seg['kind'])) {
                $fill['kind'] = $seg['kind'];
            }
            if (! $node->aliases && ! empty($seg['aliases'])) {
                $fill['aliases'] = $seg['aliases'];
            }
            if (! $node->default_tags && ! empty($seg['tags'])) {
                $fill['default_tags'] = $seg['tags'];
            }
            if (! $node->species && ! empty($seg['species'])) {
                $fill['species'] = $seg['species'];
            }
            if ($fill) {
                $node->update($fill);
            }
            $canonical[] = $node->name;
            $parent = $node;
        }

        return $parent;
    }

    /**
     * Find the node at the end of a path WITHOUT creating anything. Names and aliases match exactly (case-insensitive).
     * Null when any rung is missing: users never create tree nodes, they ask for them.
     *
     * @param  list<string>  $names
     */
    public static function resolvePath(array $names): ?self
    {
        $names = array_values(array_filter(array_map(fn ($n) => trim((string) $n), $names), fn ($n) => $n !== ''));
        if (! $names || count($names) > (int) config('catfud.brand_tree_max_depth', 5)) {
            return null;
        }
        $node = null;
        foreach ($names as $name) {
            $node = self::where('parent_id', $node?->id)->get()->first(fn (self $n) => $n->matchesName($name));
            if (! $node) {
                return null;
            }
        }

        return $node;
    }

    /** True when $name is this node's name or one of its aliases (case-insensitive). */
    public function matchesName(string $name): bool
    {
        $name = mb_strtolower(trim($name));

        return mb_strtolower($this->name) === $name
            || in_array($name, array_map('mb_strtolower', $this->aliases ?? []), true);
    }
}
