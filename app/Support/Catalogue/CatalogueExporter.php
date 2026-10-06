<?php

namespace App\Support\Catalogue;

use App\Models\BrandNode;
use App\Models\Product;
use App\Models\Scopes\VisibleScope;
use App\Models\Tag;
use App\Support\BrandCatalogue;
use App\Support\LifeStageParser;
use Illuminate\Support\Collection;

/**
 * Writes the database out as catalogue files: one ladder file per root, one product file per brand, plus the vocabulary.
 * Used to take the first draft from existing data, and as a snapshot afterwards. Every file it writes is marked
 * "reviewed": false and carries "_flags" for a person to resolve; a file already marked reviewed is never overwritten
 * unless forced.
 */
final class CatalogueExporter
{
    public function __construct(private CatalogueFiles $files) {}

    /** @return array{written:list<string>, kept:list<string>} */
    public function export(bool $force = false): array
    {
        $written = $kept = [];
        $put = function (string $path, array $data) use ($force, &$written, &$kept) {
            if (! $force && is_file($path) && ($this->files->read($path)['reviewed'] ?? false)) {
                $kept[] = $path;

                return;
            }
            $this->files->write($path, $data);
            $written[] = $path;
        };

        $put($this->files->vocabularyPath(), $this->vocabulary());

        $nodes = BrandNode::all();
        $rootSlugs = [];
        foreach ($nodes->whereNull('parent_id')->sortBy('name') as $root) {
            $rootSlugs[$root->id] = CatalogueFiles::slug($root->name);
            $put($this->files->ladderPath($rootSlugs[$root->id]), [
                'region' => $this->files->region, 'reviewed' => false, 'ladder' => $this->entry($root, $nodes),
            ]);
        }

        $byBrand = Product::withoutGlobalScope(VisibleScope::class)->with(['tags', 'barcodes', 'node'])
            ->where('moderation_status', 'approved')->whereNotNull('brand_node_id')->orderBy('id')->get()
            ->filter(fn (Product $p) => ! str_starts_with((string) $p->source, 'user:'))->groupBy('brand');
        $used = [];
        foreach ($byBrand as $brand => $products) {
            $rootId = $products->first()->node->ancestry()[0]->id;
            $slug = CatalogueFiles::slug((string) $brand);
            if (isset($used[$slug])) {
                $slug = ($rootSlugs[$rootId] ?? 'x').'-'.$slug;
            }
            $used[$slug] = true;
            $put($this->files->productsPath($slug), [
                'region' => $this->files->region, 'reviewed' => false, 'ladder' => $rootSlugs[$rootId] ?? null, 'brand' => (string) $brand,
                'products' => $products->sortBy(fn ($p) => mb_strtolower($p->path_text.'|'.$p->name))->map(fn ($p) => $this->product($p, $products))->values()->all(),
            ]);
        }

        return ['written' => $written, 'kept' => $kept];
    }

    /** @return array<string,mixed> */
    private function vocabulary(): array
    {
        return ['region' => $this->files->region, 'tags' => Tag::orderBy('group')->orderBy('sort')->get()
            ->map(fn ($t) => ['group' => $t->group, 'slug' => $t->slug, 'label' => $t->label, 'sort' => $t->sort])->all()];
    }

    /** @return array<string,mixed> */
    private function entry(BrandNode $node, Collection $all): array
    {
        $e = ['name' => $node->name, 'kind' => $node->kind];
        foreach (['aliases', 'species'] as $k) {
            if ($node->{$k}) {
                $e[$k] = $node->{$k};
            }
        }
        if ($node->default_tags) {
            $e['tags'] = $node->default_tags;
        }
        if ($node->notes) {
            $e['notes'] = $node->notes;
        }
        if (($node->status ?? 'active') !== 'active') {
            $e += array_filter(['status' => $node->status, 'status_on' => $node->status_on?->toDateString(),
                'status_confidence' => $node->status_confidence, 'status_source' => $node->status_source, 'status_note' => $node->status_note]);
        }
        $flags = [];
        if (LifeStageParser::tagKeys($node->name)) {
            $flags[] = "'{$node->name}' reads like a life stage. Is it a real line, or a stage tag on the products?";
        }
        if ($node->depth >= 4) {
            $flags[] = 'Sub-line ('.$node->depth.' levels deep). Should this be a line of its own?';
        }
        if ($flags) {
            $e['_flags'] = $flags;
        }

        $kids = $all->where('parent_id', $node->id);
        $rank = [];
        foreach (BrandCatalogue::childrenOf(array_map(fn ($n) => $n->name, $node->ancestry())) as $i => $c) {
            $rank[mb_strtolower($c['name'])] = $i;
        }
        $kids = $kids->sortBy(fn ($k) => sprintf('%04d %s', $rank[mb_strtolower($k->name)] ?? 9999, mb_strtolower($k->name)))->values();
        if ($kids->isNotEmpty()) {
            $e['children'] = $kids->map(fn ($k) => $this->entry($k, $all))->all();
        }

        return $e;
    }

    /** @return array<string,mixed> */
    private function product(Product $p, Collection $siblings): array
    {
        $out = ['path' => array_map(fn ($n) => $n->name, $p->node->ancestry()), 'name' => $p->name];
        $out += array_filter([
            'title_as_listed' => $p->title_as_listed, 'gtin' => $p->gtin, 'import_key' => $p->import_key,
            'species' => $p->species, 'kind' => $p->kind?->value, 'form' => $p->form, 'texture' => $p->texture,
            'tags' => $p->tags->map(fn ($t) => $t->group.':'.$t->slug)->sort()->values()->all(),
            'description' => $p->description, 'ingredients' => $p->ingredients, 'nutrition' => $p->nutrition,
            'image_url' => $p->image_url, 'source_url' => $p->source_url, 'source' => $p->source,
            'audit_status' => $p->audit_status?->value, 'audit_notes' => $p->audit_notes,
            'last_verified_at' => $p->last_verified_at?->toDateString(),
            'barcodes' => $p->barcodes->map(fn ($b) => array_filter(['gtin' => $b->gtin, 'pack_label' => $b->pack_label]))->all(),
            'meta' => $p->meta,
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');

        $flags = [];
        if ($p->texture && ! $p->tags->contains(fn ($t) => in_array($t->group, ['texture', 'medium'], true))) {
            $flags[] = "texture '{$p->texture}' is not tagged";
        }
        if (! $p->tags->contains(fn ($t) => $t->group === 'life_stage')) {
            $flags[] = 'no life stage tag';
        }
        if ($flags) {
            $out['_flags'] = $flags;
        }

        return $out;
    }
}
