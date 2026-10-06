<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminNodesRequest;
use App\Http\Requests\Admin\RetireNodeRequest;
use App\Http\Requests\Admin\StoreNodeRequest;
use App\Http\Resources\BrandNodeResource;
use App\Models\BrandNode;
use App\Models\Product;
use App\Models\Scopes\VisibleScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The brand ladder as the admin tool manages it. Renaming, moving and merging nodes use the existing
 * PATCH /brand-nodes/{id} and POST /brand-nodes/{id}/merge endpoints; this adds listing, creating, deleting and retiring.
 */
class NodeController extends Controller
{
    /**
     * Every node in the region with its product counts. Counts include everything beneath a node.
     *
     * @response array{nodes: array<int, array{id: int, parent_id: ?int, name: string, kind: ?string, depth: int, path_text: string,
     *   status: string, previous_status: ?string, effective_status: string, successor_id: ?int, aliases: ?string[], logo: ?string, stages: array<int, array{slug: string, label: string, count: int}>, product_count: int, child_count: int}>}
     */
    public function index(AdminNodesRequest $request): JsonResponse
    {
        $nodes = $this->inTreeOrder(BrandNode::all());
        $own = Product::withoutGlobalScope(VisibleScope::class)->with([])->whereNotNull('brand_node_id')
            ->whereNotIn('moderation_status', ['merged'])->selectRaw('brand_node_id, count(*) as n')->groupBy('brand_node_id')->pluck('n', 'brand_node_id');
        $children = $nodes->groupBy('parent_id')->map->count();
        $byId = $nodes->keyBy('id');

        $total = function (BrandNode $n) use ($nodes, $own) {
            return $nodes->filter(fn ($o) => $o->path_key === $n->path_key || str_starts_with($o->path_key, $n->path_key.'>'))->sum(fn ($o) => (int) ($own[$o->id] ?? 0));
        };
        $effective = function (BrandNode $n) use ($byId) {
            for ($cur = $n; $cur; $cur = $cur->parent_id ? $byId[$cur->parent_id] ?? null : null) {
                if (($cur->status ?? 'active') !== 'active') {
                    return $cur->status;
                }
            }

            return 'active';
        };

        // Life stages actually seen on the products under each node (witnessed, never presumed).
        $stageRows = DB::table('product_tag')->join('tags', 'tags.id', '=', 'product_tag.tag_id')->join('products', 'products.id', '=', 'product_tag.product_id')
            ->where('tags.group', 'life_stage')->whereNotNull('products.brand_node_id')->where('products.moderation_status', '!=', 'merged')
            ->selectRaw('products.brand_node_id as node_id, tags.slug, tags.label, tags.sort, count(*) as n')->groupBy('products.brand_node_id', 'tags.slug', 'tags.label', 'tags.sort')->get()->groupBy('node_id');
        $stages = function (BrandNode $n) use ($nodes, $stageRows) {
            $acc = [];
            foreach ($nodes as $o) {
                if (($o->path_key === $n->path_key || str_starts_with($o->path_key, $n->path_key.'>')) && isset($stageRows[$o->id])) {
                    foreach ($stageRows[$o->id] as $r) {
                        $acc[$r->slug] ??= ['slug' => $r->slug, 'label' => $r->label, 'count' => 0, 'sort' => (int) $r->sort];
                        $acc[$r->slug]['count'] += (int) $r->n;
                    }
                }
            }
            usort($acc, fn ($a, $b) => $a['sort'] <=> $b['sort']);

            return array_map(fn ($a) => ['slug' => $a['slug'], 'label' => $a['label'], 'count' => $a['count']], $acc);
        };

        $rows = $nodes
            ->when($request->query('parent'), fn ($c, $p) => $c->where('parent_id', (int) $p))
            ->when($request->query('ever'), fn ($c, $s) => $c->filter(fn ($n) => ($n->status ?? 'active') === $s || $n->previous_status === $s))
            ->when($request->query('status'), fn ($c, $s) => $c->filter(fn ($n) => ($n->status ?? 'active') === $s))
            ->when($request->query('q'), fn ($c, $q) => $c->filter(fn ($n) => $n->matchesName($q) || str_contains($n->path_key, mb_strtolower($q))))
            ->map(fn (BrandNode $n) => [
                'id' => $n->id, 'parent_id' => $n->parent_id, 'name' => $n->name, 'kind' => $n->kind, 'depth' => $n->depth,
                'path_text' => implode(' › ', array_map(fn ($seg) => $seg, $this->names($n, $byId))),
                'status' => $n->status ?? 'active', 'previous_status' => $n->previous_status, 'effective_status' => $effective($n), 'successor_id' => $n->successor_id, 'aliases' => $n->aliases, 'logo' => app(\App\Support\ImageStore::class)->logoUrl($n),
                'stages' => $stages($n), 'product_count' => $total($n), 'child_count' => (int) ($children[$n->id] ?? 0),
            ]);
        if ($request->boolean('empty')) {
            $rows = $rows->filter(fn ($r) => $r['product_count'] === 0);
        }

        return response()->json(['nodes' => $rows->values()->all()]);
    }

    /** Add a rung. Use this (not a user request) to create a brand, line or sub-line that does not exist yet. */
    public function store(StoreNodeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $segments = [];
        if (! empty($data['parent_id'])) {
            foreach (BrandNode::findOrFail($data['parent_id'])->ancestry() as $n) {
                $segments[] = ['name' => $n->name];
            }
        }
        if (count($segments) + 1 > (int) config('catfud.brand_tree_max_depth', 5)) {
            return response()->json(['message' => 'The ladder cannot go that deep.', 'errors' => ['parent_id' => ['Too deep.']]], 422);
        }
        $segments[] = array_filter(['name' => $data['name'], 'kind' => $data['kind'] ?? null, 'species' => $data['species'] ?? null, 'aliases' => $data['aliases'] ?? null]);

        $existing = BrandNode::where('parent_id', $data['parent_id'] ?? null)->get()->first(fn (BrandNode $n) => $n->matchesName($data['name']));
        $node = BrandNode::ensurePath($segments);

        return response()->json(['node' => new BrandNodeResource($node), 'created' => $existing === null], $existing ? 200 : 201);
    }

    /** Delete a node only when nothing hangs from it: no sub-lines and no products. Anything else is merged or retired, never deleted. */
    public function destroy(int $id): JsonResponse
    {
        $node = BrandNode::findOrFail($id);
        $has = $node->children()->exists() || Product::withoutGlobalScope(VisibleScope::class)->where('brand_node_id', $node->id)->exists();
        if ($has) {
            return response()->json(['message' => 'This node still has sub-lines or products. Merge it into another node, or retire it.', 'errors' => ['node' => ['In use.']]], 422);
        }
        $node->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    /**
     * Set a rung's status: active, retiring (being phased out), retired (gone from the market) or disabled (switched off by
     * an admin whatever the market is doing). Nothing is deleted or hidden from people who own the product. The status is
     * inherited by everything beneath, and the picker stops offering retired and disabled rungs to non-staff for new
     * products. `status_on` is when it took (or takes) effect. Send `preview: true` to see what would be affected first.
     *
     * @response array{node: BrandNodeResource, impact: array{nodes: int, products: int, pending_products: int, pantry_items: int, households: int}, applied: bool}
     */
    public function retire(RetireNodeRequest $request, int $id): JsonResponse
    {
        $node = BrandNode::findOrFail($id);
        $data = $request->validated();

        if (! empty($data['successor_id']) && ((int) $data['successor_id'] === $node->id)) {
            return response()->json(['message' => 'A node cannot succeed itself.', 'errors' => ['successor_id' => ['Pick a different node.']]], 422);
        }
        $impact = $this->impact($node);
        if ($request->boolean('preview')) {
            return response()->json(['node' => new BrandNodeResource($node), 'impact' => $impact, 'applied' => false]);
        }

        $active = $data['status'] === 'active';
        $node->update([
            'status' => $data['status'],
            'status_on' => $active ? null : ($data['status_on'] ?? $node->status_on),
            'status_confidence' => $active ? null : ($data['confidence'] ?? null),
            'status_source' => $active ? null : ($data['source'] ?? null),
            'status_note' => $active ? null : ($data['note'] ?? null),
            'successor_id' => $active ? null : ($data['successor_id'] ?? null),
        ]);

        return response()->json(['node' => new BrandNodeResource($node->fresh()), 'impact' => $impact, 'applied' => true]);
    }

    /** @return array{nodes:int, products:int, pending_products:int, pantry_items:int, households:int} */
    private function impact(BrandNode $node): array
    {
        $ids = BrandNode::where('path_key', $node->path_key)->orWhere('path_key', 'like', str_replace(['%', '_'], ['\%', '\_'], $node->path_key).'>%')->pluck('id');
        $products = Product::withoutGlobalScope(VisibleScope::class)->with([])->whereIn('brand_node_id', $ids);
        $pantry = DB::table('inventory_items')->whereIn('product_id', (clone $products)->select('id'))->whereIn('status', ['stocked', 'low']);

        return [
            'nodes' => $ids->count(),
            'products' => (clone $products)->where('moderation_status', 'approved')->count(),
            'pending_products' => (clone $products)->whereIn('moderation_status', ['pending', 'needs_changes'])->count(),
            'pantry_items' => (clone $pantry)->count(),
            'households' => (clone $pantry)->distinct()->count('user_id'),
        ];
    }

    /** Parents before children, siblings alphabetical, so every branch stays together (path_key order splits "Adult" from its sub-lines). */
    private function inTreeOrder($all)
    {
        $by = $all->groupBy('parent_id')->map(fn ($g) => $g->sortBy(fn ($n) => mb_strtolower($n->name))->values());
        $out = collect();
        $walk = function ($parentId) use (&$walk, $by, $out) {
            foreach ($by[$parentId] ?? [] as $n) {
                $out->push($n);
                $walk($n->id);
            }
        };
        $walk(null);

        return $out;
    }

    /** @return list<string> */
    private function names(BrandNode $n, $byId): array
    {
        $out = [];
        for ($cur = $n; $cur; $cur = $cur->parent_id ? $byId[$cur->parent_id] ?? null : null) {
            array_unshift($out, $cur->name);
        }

        return $out;
    }
}
