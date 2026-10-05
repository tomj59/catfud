<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRegion;
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
    use BelongsToRegion;

    protected $fillable = ['region', 'parent_id', 'name', 'kind', 'depth', 'path_key', 'aliases', 'default_tags', 'notes'];

    protected function casts(): array
    {
        return ['aliases' => 'array', 'default_tags' => 'array'];
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
     * @param  list<array{name:string, kind?:?string, aliases?:list<string>, tags?:list<string>}>  $segments
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
        $keys = [];
        foreach ($segments as $i => $seg) {
            $name = trim(preg_replace('/\s+/', ' ', $seg['name']));
            $keys[] = mb_strtolower($name);
            $pathKey = mb_strtolower(Region::current()).'|'.implode('>', $keys);

            // A hand-typed path carries no kinds; borrow one from another node with the same name ("Pro Plan" is a brand).
            $kind = $seg['kind'] ?? self::where('name', $name)->whereNotNull('kind')->value('kind');

            $node = self::firstOrCreate(
                ['path_key' => $pathKey],
                ['parent_id' => $parent?->id, 'name' => $name, 'kind' => $kind, 'depth' => $i + 1,
                    'aliases' => $seg['aliases'] ?? null, 'default_tags' => $seg['tags'] ?? null],
            );

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
            if ($fill) {
                $node->update($fill);
            }
            $parent = $node;
        }

        return $parent;
    }
}
