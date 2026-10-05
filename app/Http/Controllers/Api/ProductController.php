<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditStatus;
use App\Enums\ProductKind;
use App\Http\Controllers\Controller;
use App\Models\BrandNode;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Tag;
use App\Support\AdvisoryPresenter;
use App\Support\BrandTreeMapper;
use App\Support\Gtin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class ProductController extends Controller
{
    public function __construct(private BrandTreeMapper $mapper) {}

    /**
     * Search the shared catalogue (the signed-in user's region only).
     * ?q= every word must match brand, name, line or the brand path/aliases; ?node= limits to a branch of the tree;
     * ?form=wet|dry; ?tag[]=texture:pate includes, ?exclude_tag[]=texture:chunks excludes; ?barcode=missing|present;
     * ?audit=; ?per_page= (1-50).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'kind' => ['nullable', Rule::in(ProductKind::values())],
            'barcode' => ['nullable', Rule::in(['missing', 'present'])],
            'audit' => ['nullable', Rule::in(AuditStatus::values())],
            'form' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'node' => ['nullable', 'integer'],
            'tag' => ['nullable', 'array', 'max:20'],
            'tag.*' => ['string', 'max:60'],
            'exclude_tag' => ['nullable', 'array', 'max:20'],
            'exclude_tag.*' => ['string', 'max:60'],
        ]);

        $query = Product::query()->orderBy('brand')->orderBy('line')->orderBy('name');

        // Every word must match somewhere, so "purina classic" finds Purina > Fancy Feast > Classic.
        foreach (preg_split('/\s+/', trim((string) $request->query('q')), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($word)).'%';
            $query->where(fn ($w) => $w->whereRaw('lower(brand) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like])
                ->orWhereRaw('lower(line) like ?', [$like])->orWhereRaw('lower(search_text) like ?', [$like]));
        }
        if ($barcode = $request->query('barcode')) {
            $barcode === 'missing' ? $query->whereNull('gtin') : $query->whereNotNull('gtin');
        }
        if ($audit = $request->query('audit')) {
            $query->where('audit_status', $audit);
        }
        if ($form = $request->query('form')) {
            $query->whereRaw('lower(form) = ?', [strtolower($form)]); // wet / dry / freeze-dried; omit for everything
        }
        if ($species = $request->query('species')) {
            $query->where('species', $species);
        }
        if ($kind = $request->query('kind')) {
            $query->where('kind', $kind);
        }
        if ($nodeId = $request->query('node')) {
            $query->whereIn('brand_node_id', $this->subtreeIds((int) $nodeId));
        }

        // Tags: different groups AND together, several tags in one group are alternatives (pate OR mousse).
        $include = [];
        foreach ((array) $request->query('tag', []) as $key) {
            [$group, $slug] = array_pad(explode(':', $key, 2), 2, '');
            $include[$group][] = $slug;
        }
        foreach ($include as $group => $slugs) {
            $query->whereHas('tags', fn ($t) => $t->where('group', $group)->whereIn('slug', $slugs));
        }
        foreach ((array) $request->query('exclude_tag', []) as $key) {
            [$group, $slug] = array_pad(explode(':', $key, 2), 2, '');
            $query->whereDoesntHave('tags', fn ($t) => $t->where('group', $group)->where('slug', $slug));
        }

        return response()->json($query->paginate((int) $request->query('per_page', 25)));
    }

    /** @return list<int> a node and everything beneath it */
    private function subtreeIds(int $nodeId): array
    {
        $node = BrandNode::find($nodeId);
        if (! $node) {
            return [0];
        }

        return BrandNode::where('path_key', $node->path_key)
            ->orWhere('path_key', 'like', str_replace(['%', '_'], ['\%', '\_'], $node->path_key).'>%')
            ->pluck('id')->all();
    }

    /**
     * Browse the brand tree one level at a time: ?parent= (omit for the top). Only branches that hold products are
     * listed, with a count that honours the same ?form= and ?barcode=missing filters the search uses.
     */
    public function nodes(Request $request): JsonResponse
    {
        $request->validate(['parent' => ['nullable', 'integer'], 'form' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', Rule::in(['missing', 'present'])]]);

        $counts = Product::query()->whereNotNull('brand_node_id')
            ->when($request->query('form'), fn ($q, $f) => $q->whereRaw('lower(form) = ?', [strtolower($f)]))
            ->when($request->query('barcode'), fn ($q, $b) => $b === 'missing' ? $q->whereNull('gtin') : $q->whereNotNull('gtin'))
            ->selectRaw('brand_node_id, count(*) as n')->groupBy('brand_node_id')->pluck('n', 'brand_node_id');

        $nodes = BrandNode::orderBy('name')->get();
        $total = fn (BrandNode $n) => $nodes->filter(fn ($o) => $o->path_key === $n->path_key || str_starts_with($o->path_key, $n->path_key.'>'))
            ->sum(fn ($o) => (int) ($counts[$o->id] ?? 0));

        $parent = $request->query('parent') ? BrandNode::find($request->query('parent')) : null;
        $level = $nodes->filter(fn ($n) => $parent ? $n->parent_id === $parent->id : $n->parent_id === null);

        return response()->json([
            'parent' => $parent ? ['id' => $parent->id, 'name' => $parent->name, 'kind' => $parent->kind,
                'path' => collect($parent->ancestry())->map(fn ($n) => ['id' => $n->id, 'name' => $n->name])->all(),
                'products_here' => (int) ($counts[$parent->id] ?? 0)] : null,
            'nodes' => $level->map(fn ($n) => [
                'id' => $n->id, 'name' => $n->name, 'kind' => $n->kind, 'depth' => $n->depth,
                'product_count' => $total($n), 'has_children' => $nodes->contains(fn ($o) => $o->parent_id === $n->id && $total($o) > 0),
            ])->filter(fn ($n) => $n['product_count'] > 0)->values()->all(),
        ]);
    }

    /** The facet vocabulary (texture, medium, life stage, diet) grouped for the filter and edit screens. */
    public function tags(): JsonResponse
    {
        return response()->json(['tags' => Tag::orderBy('group')->orderBy('sort')->get()->groupBy('group')]);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        return response()->json($this->withAdvisories($request, $product));
    }

    /**
     * Look up a product by whatever the scanner (or the user's fingers) produced: its primary barcode or any extra
     * pack barcode. Found: 200. Valid code but unknown product: 404 with `gtin`, so the app can offer "add it".
     * Not a valid barcode: 422.
     */
    public function lookup(Request $request, string $code): JsonResponse
    {
        $gtin = Gtin::normalize($code);

        if ($gtin === null) {
            return response()->json([
                'message' => 'That is not a valid UPC/EAN (wrong length or check digit).',
                'errors' => ['code' => ['Invalid barcode.']],
            ], 422);
        }

        $product = Product::findByCode($gtin);

        if (! $product) {
            return response()->json([
                'message' => 'No product with that barcode yet.',
                'gtin' => $gtin,
                'upc_a' => Gtin::toUpcA($gtin),
            ], 404);
        }

        return response()->json($this->withAdvisories($request, $product));
    }

    /** The product plus any Public Advisories that may relate to it, each attributed to its source. @return array<string,mixed> */
    private function withAdvisories(Request $request, Product $product): array
    {
        return [
            'product' => $product,
            'advisories' => AdvisoryPresenter::forProduct($product->id, $request->user()),
            'advisory_disclaimer' => config('catfud.advisory_disclaimer'),
        ];
    }

    /** Add a product that the scan lookup could not find. The creator is recorded as provenance. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gtin' => ['required', 'string'],
            'brand' => ['required_without:path', 'nullable', 'string', 'max:255'],
            'path' => ['nullable', 'string', 'max:500'],
            'name' => ['required', 'string', 'max:255'],
            'species' => ['nullable', 'string', 'max:50'],
            'kind' => ['nullable', Rule::in(ProductKind::values())],
            'form' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'ingredients' => ['nullable', 'string', 'max:5000'],
            'nutrition' => ['nullable', 'array'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
        ]);

        $gtin = Gtin::normalize($data['gtin']);
        if ($gtin === null) {
            return response()->json([
                'message' => 'That is not a valid UPC/EAN (wrong length or check digit).',
                'errors' => ['gtin' => ['Invalid barcode.']],
            ], 422);
        }

        if ($existing = Product::findByCode($gtin)) {
            return response()->json(['message' => 'A product with that barcode already exists.', 'product' => $existing], 409);
        }

        $path = $this->segmentsFromPath($data['path'] ?? null);
        if ($path === false) {
            return response()->json(['message' => 'A brand path can be at most '.config('catfud.brand_tree_max_depth').' levels deep.', 'errors' => ['path' => ['Too many levels.']]], 422);
        }

        $product = Product::create([
            ...collect($data)->except(['path', 'tags'])->all(),
            'brand' => $data['brand'] ?? ($path[0]['name'] ?? ''),
            'gtin' => $gtin,
            'species' => $data['species'] ?? 'cat',
            'kind' => $data['kind'] ?? 'food',
            'source' => 'user:'.$request->user()->id,
            'created_by' => $request->user()->id,
        ]);

        if ($path) {
            $this->mapper->place($product, BrandNode::ensurePath($path));
            $product->forceFill(['meta' => [...($product->meta ?? []), 'path_locked' => true]])->save();
        } else {
            $this->mapper->apply($product);
        }
        $this->mapper->attachTags($product, $data['tags'] ?? []);

        return response()->json(['product' => $product->fresh()], 201);
    }

    /** Progress for the seed -> wire-up -> audit pass. */
    public function auditSummary(): JsonResponse
    {
        return response()->json([
            'total' => Product::count(),
            'without_barcode' => Product::whereNull('gtin')->count(),
            'without_image' => Product::whereNull('image_url')->count(),
            'unreviewed' => Product::where('audit_status', AuditStatus::Unreviewed->value)->count(),
            'reviewed' => Product::where('audit_status', AuditStatus::Reviewed->value)->count(),
            'needs_changes' => Product::where('audit_status', AuditStatus::NeedsChanges->value)->count(),
        ]);
    }

    /**
     * Wire a scanned barcode to a product. A product with no barcode gets it as its primary barcode; a product that
     * already has one gets this as an extra pack barcode (single can, multipack, case). 409 if the code is already
     * on a product (the response names it, so the app can say so).
     */
    public function attachBarcode(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate(['gtin' => ['required', 'string'], 'pack_label' => ['nullable', 'string', 'max:100']]);

        $gtin = Gtin::normalize($data['gtin']);
        if ($gtin === null) {
            return response()->json([
                'message' => 'That is not a valid UPC/EAN (wrong length or check digit).',
                'errors' => ['gtin' => ['Invalid barcode.']],
            ], 422);
        }

        if ($owner = Product::findByCode($gtin)) {
            return response()->json([
                'message' => $owner->id === $product->id ? 'That barcode is already on this product.' : 'That barcode already belongs to another product.',
                'product' => $owner,
            ], 409);
        }

        if ($product->gtin === null) {
            $product->update(['gtin' => $gtin, 'last_edited_by' => $request->user()->id]);
        } else {
            ProductBarcode::create(['product_id' => $product->id, 'gtin' => $gtin, 'pack_label' => $data['pack_label'] ?? null, 'added_by' => $request->user()->id]);
            $product->update(['last_edited_by' => $request->user()->id]);
        }

        return response()->json(['product' => $product->fresh()]);
    }

    /**
     * Correct a catalogue record during the audit pass. `path` ("Purina > Pro Plan > Complete Essentials") moves the
     * product within the brand tree; `tags` replaces its facet tags. The primary barcode is changed only through
     * attachBarcode. Pilot note: any signed-in user can edit the shared catalogue; real roles are a later question.
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'path' => ['nullable', 'string', 'max:500'],
            'texture' => ['nullable', 'string', 'max:255'],
            'form' => ['nullable', 'string', 'max:100'],
            'kind' => ['sometimes', Rule::in(ProductKind::values())],
            'description' => ['nullable', 'string', 'max:5000'],
            'ingredients' => ['nullable', 'string', 'max:5000'],
            'nutrition' => ['nullable', 'array'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'source_url' => ['nullable', 'url', 'max:2048'],
            'title_as_listed' => ['nullable', 'string', 'max:500'],
            'audit_status' => ['sometimes', Rule::in(AuditStatus::values())],
            'audit_notes' => ['nullable', 'string', 'max:5000'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
        ]);

        $path = $this->segmentsFromPath($data['path'] ?? null);
        if ($path === false) {
            return response()->json(['message' => 'A brand path can be at most '.config('catfud.brand_tree_max_depth').' levels deep.', 'errors' => ['path' => ['Too many levels.']]], 422);
        }

        $product->fill(collect($data)->except(['path', 'tags'])->all());
        $product->last_edited_by = $request->user()->id;
        if (($data['audit_status'] ?? null) === AuditStatus::Reviewed->value) {
            $product->last_verified_at = now();
        }
        $product->save();

        if ($path) {
            $this->mapper->place($product, BrandNode::ensurePath($path));
            $product->forceFill(['meta' => [...($product->meta ?? []), 'path_locked' => true]])->save();
        }
        if (array_key_exists('tags', $data)) {
            $keys = collect($data['tags'])->map(fn ($k) => explode(':', $k, 2))->filter(fn ($p) => count($p) === 2);
            $ids = $keys->map(fn ($p) => Tag::where('group', $p[0])->where('slug', $p[1])->value('id'))->filter()->all();
            $product->tags()->sync($ids);
        }

        return response()->json(['product' => $product->fresh()]);
    }

    /** @return list<array{name:string}>|null|false null: no path given; false: too deep */
    private function segmentsFromPath(?string $path): array|null|false
    {
        if ($path === null || trim($path) === '') {
            return null;
        }
        $segments = array_values(array_filter(array_map(fn ($s) => ['name' => trim($s)], preg_split('/\s*(?:>|›)\s*/u', $path)), fn ($s) => $s['name'] !== ''));
        try {
            return count($segments) > (int) config('catfud.brand_tree_max_depth', 5) ? false : ($segments ?: null);
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
