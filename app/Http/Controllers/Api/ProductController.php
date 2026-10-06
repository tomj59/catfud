<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditStatus;
use App\Enums\ProductKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\AttachBarcodeRequest;
use App\Http\Requests\BrandChoicesRequest;
use App\Http\Requests\BrandNodesRequest;
use App\Http\Requests\IndexProductsRequest;
use App\Http\Requests\MergeBrandNodeRequest;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateBrandNodeRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\BrandNodeResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductVersionResource;
use App\Http\Resources\TagResource;
use App\Models\BrandNode;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Tag;
use App\Support\AdvisoryPresenter;
use App\Support\BrandCatalogue;
use App\Support\BrandNodeEditor;
use App\Support\BrandTreeMapper;
use App\Support\Gtin;
use App\Support\LadderPath;
use App\Support\Region;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use RuntimeException;

class ProductController extends Controller
{
    public function __construct(private BrandTreeMapper $mapper) {}

    /**
     * Search the shared catalogue (the signed-in user's region only).
     * ?q= every word must match brand, name, line or the brand path/aliases; ?node= limits to a branch of the tree;
     * ?form=wet|dry; ?tag[]=texture:pate includes, ?exclude_tag[]=texture:chunks excludes; ?barcode=missing|present;
     * ?audit=; ?per_page= (1-50).
     */
    public function index(IndexProductsRequest $request): AnonymousResourceCollection
    {
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

        return ProductResource::collection($query->with(['tags', 'barcodes'])->paginate((int) $request->query('per_page', 25)));
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
     *
     * @response array{parent: ?array{id: int, name: string, kind: ?string, path: array<int, array{id: int, name: string}>, products_here: int}, nodes: array<int, array{id: int, name: string, kind: ?string, depth: int, product_count: int, has_children: bool}>}
     */
    public function nodes(BrandNodesRequest $request): JsonResponse
    {
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

    /**
     * The choices for one rung of the brand-ladder picker. ?path=Purina > Pro Plan returns what can follow it: nodes that
     * already exist (even with no products yet) plus the region's curated ladder (App\Support\BrandCatalogue). ?species=cat hides names sold only for other species.
     * Each choice carries a logo URL when public/images/brands/{slug}.png|svg|jpg|webp exists.
     *
     * @response array{path: string[], max_depth: int, can_go_deeper: bool, choices: array<int, array{name: string, known: bool, species: ?string[], status: string, logo: ?string}>}
     */
    public function choices(BrandChoicesRequest $request): JsonResponse
    {
        $names = collect(explode('>', (string) $request->query('path')))
            ->map(fn ($n) => trim(preg_replace('/\s+/', ' ', $n)))->filter()->values();
        $max = (int) config('catfud.brand_tree_max_depth', 5);
        $species = $request->query('species');
        $keys = $names->map(fn ($n) => mb_strtolower($n))->all();
        $region = Region::current();

        $parent = $keys ? BrandNode::where('path_key', mb_strtolower($region).'|'.implode('>', $keys))->first() : null;
        $existing = ($keys && ! $parent) ? collect() : BrandNode::where('parent_id', $parent?->id)->get();
        $curated = BrandCatalogue::childrenOf($names->all());

        $out = [];
        foreach ($existing as $n) {
            if ($n->effectiveStatus() === 'discontinued' && ! $request->user()->isStaff()) {
                continue;   // nobody adds new products to a discontinued line; existing ones keep working
            }
            $out[mb_strtolower($n->name)] = ['name' => $n->name, 'known' => true, 'species' => $n->species, 'status' => $n->effectiveStatus()];
        }
        foreach ($request->user()->isStaff() ? $curated : [] as $e) {     // only staff see names that are not real nodes yet
            $out[mb_strtolower($e['name'])] ??= ['name' => $e['name'], 'known' => false, 'species' => $e['species'] ?? null, 'status' => 'active'];
        }
        // Curated names lead, in the file's order; anything people added themselves follows alphabetically.
        $rank = [];
        foreach ($curated as $i => $e) {
            $rank[mb_strtolower($e['name'])] = $i;
        }
        $choices = collect($out)->filter(fn ($c) => BrandCatalogue::forSpecies($c['species'], $species))
            ->sortBy(fn ($c, $k) => sprintf('%04d %s', $rank[$k] ?? 9999, $k))->values()
            ->map(fn ($c) => $c + ['logo' => $this->logoFor($c['name'])])->all();

        return response()->json(['path' => $names->all(), 'max_depth' => $max, 'can_go_deeper' => $names->count() < $max, 'choices' => $names->count() < $max ? $choices : []]);
    }

    private function logoFor(string $name): ?string
    {
        $slug = Str::slug(str_replace("'", '', $name));
        foreach (['png', 'svg', 'jpg', 'webp'] as $ext) {
            if (is_file(public_path("images/brands/{$slug}.{$ext}"))) {
                return "/images/brands/{$slug}.{$ext}";
            }
        }

        return null;
    }

    /** Rename, re-kind, re-species or move a brand-tree node. A rename keeps the old name as an alias. */
    public function updateNode(UpdateBrandNodeRequest $request, BrandNode $brandNode, BrandNodeEditor $editor): JsonResponse
    {
        $data = $request->validated();

        try {
            return response()->json(['node' => new BrandNodeResource($editor->update($brandNode, $data))]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['node' => [$e->getMessage()]]], 422);
        }
    }

    /** Fold one node into another. Products, lines and barcodes move across; the old names become aliases. */
    public function mergeNode(MergeBrandNodeRequest $request, BrandNode $brandNode, BrandNodeEditor $editor): JsonResponse
    {
        $data = $request->validated();
        $target = BrandNode::find($data['into']);
        if (! $target) {
            return response()->json(['message' => 'That node does not exist.', 'errors' => ['into' => ['Unknown node.']]], 422);
        }

        try {
            return response()->json(['node' => new BrandNodeResource($editor->merge($brandNode, $target))]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['node' => [$e->getMessage()]]], 422);
        }
    }

    /**
     * The facet vocabulary (texture, medium, life stage, diet) grouped for the filter and edit screens.
     *
     * @response array{tags: array<string, array<int, array{id: int, group: string, slug: string, label: string}>>}
     */
    public function tags(): JsonResponse
    {
        return response()->json(['tags' => Tag::orderBy('group')->orderBy('sort')->get()->groupBy('group')->map(fn ($g) => TagResource::collection($g)->resolve())]);
    }

    /**
     * One product with any Public Advisories that may relate to it.
     *
     * @response array{product: ProductResource, advisories: array<int, array<string, mixed>>, advisory_disclaimer: string}
     */
    public function show(Request $request, Product $product): JsonResponse
    {
        return response()->json($this->withAdvisories($request, $product));
    }

    /**
     * Look up a product by whatever the scanner (or the user's fingers) produced: its primary barcode or any extra
     * pack barcode. Found: 200. Valid code but unknown product: 404 with `gtin`, so the app can offer "add it".
     * Not a valid barcode: 422.
     *
     * @response array{product: ProductResource, advisories: array<int, array<string, mixed>>, advisory_disclaimer: string}
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
            'product' => (new ProductResource($product->loadMissing(['tags', 'barcodes'])))->resolve($request),
            'advisories' => AdvisoryPresenter::forProduct($product->id, $request->user()),
            'advisory_disclaimer' => config('catfud.advisory_disclaimer'),
        ];
    }

    /** Add a product that the scan lookup could not find. The creator is recorded as provenance. */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();

        $gtin = Gtin::normalize($data['gtin']);
        if ($gtin === null) {
            return response()->json([
                'message' => 'That is not a valid UPC/EAN (wrong length or check digit).',
                'errors' => ['gtin' => ['Invalid barcode.']],
            ], 422);
        }

        if ($existing = Product::findByCode($gtin)) {
            return response()->json(['message' => 'A product with that barcode already exists.', 'product' => new ProductResource($existing->loadMissing(['tags', 'barcodes']))], 409);
        }

        $user = $request->user();
        $path = $this->segmentsFromPath($data['path'] ?? null);
        if ($path === false) {
            return response()->json(['message' => 'A brand path can be at most '.config('catfud.brand_tree_max_depth').' levels deep.', 'errors' => ['path' => ['Too many levels.']]], 422);
        }
        // Someone who typed only a brand (no ladder) is asking for that brand as a one-rung ladder.
        $typed = $path ?: (filled($data['brand'] ?? null) ? [['name' => trim($data['brand'])]] : null);

        $product = Product::create([
            ...collect($data)->except(['path', 'tags'])->all(),
            'brand' => $data['brand'] ?? ($typed[0]['name'] ?? ''),
            'gtin' => $gtin,
            'species' => $data['species'] ?? 'cat',
            'kind' => $data['kind'] ?? 'food',
            'source' => 'user:'.$user->id,
            'created_by' => $user->id,
            // Staff add to the catalogue directly. Everyone else's additions are private to them until a moderator approves them.
            'moderation_status' => $user->isStaff() ? 'approved' : 'pending',
        ]);

        if ($user->isStaff()) {
            if ($path) {
                $this->mapper->place($product, BrandNode::ensurePath($path));
                $product->forceFill(['meta' => [...($product->meta ?? []), 'path_locked' => true]])->save();
            } else {
                $this->mapper->apply($product);
            }
        } else {
            $this->placeOrRequest($product, $typed);
        }
        $this->mapper->attachTags($product, $data['tags'] ?? []);

        return response()->json(['product' => new ProductResource($product->fresh(['tags', 'barcodes']))], 201);
    }

    /**
     * Progress for the seed -> wire-up -> audit pass.
     *
     * @response array{total: int, without_barcode: int, without_image: int, unreviewed: int, reviewed: int, needs_changes: int}
     */
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
    public function attachBarcode(AttachBarcodeRequest $request, Product $product): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isStaff() || ($product->isOwnedBy($user) && ! $product->isPublic()), 403, 'Only a moderator can add a barcode to the public catalogue.');

        $data = $request->validated();

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
                'product' => new ProductResource($owner->loadMissing(['tags', 'barcodes'])),
            ], 409);
        }

        if ($product->gtin === null) {
            $product->update(['gtin' => $gtin, 'last_edited_by' => $request->user()->id]);
        } else {
            ProductBarcode::create(['product_id' => $product->id, 'gtin' => $gtin, 'pack_label' => $data['pack_label'] ?? null, 'added_by' => $request->user()->id]);
            $product->update(['last_edited_by' => $request->user()->id]);
        }

        return response()->json(['product' => new ProductResource($product->fresh(['tags', 'barcodes']))]);
    }

    /**
     * Correct a catalogue record during the audit pass. `path` ("Purina > Pro Plan > Complete Essentials") moves the
     * product within the brand tree; `tags` replaces its facet tags. The primary barcode is changed only through
     * attachBarcode. Pilot note: any signed-in user can edit the shared catalogue; real roles are a later question.
     */
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $user = $request->user();
        // Staff can edit anything they can see. Everyone else can only edit what they contributed and nobody has approved yet:
        // a change to the public catalogue is not theirs to make.
        abort_unless($user->isStaff() || ($product->isOwnedBy($user) && ! $product->isPublic()), 403, 'Only a moderator can change the public catalogue.');

        $data = $request->validated();

        $path = $this->segmentsFromPath($data['path'] ?? null);
        if ($path === false) {
            return response()->json(['message' => 'A brand path can be at most '.config('catfud.brand_tree_max_depth').' levels deep.', 'errors' => ['path' => ['Too many levels.']]], 422);
        }

        if (! $user->isStaff()) {
            unset($data['audit_status'], $data['audit_notes']);     // the audit pass is a staff job
        }
        $product->fill(collect($data)->except(['path', 'tags', 'formula_change', 'version_note'])->all());
        $product->formulaChange = $data['formula_change'] ?? null;
        $product->formulaUser = $request->user()->id;
        $product->formulaNote = $data['version_note'] ?? null;
        $product->last_edited_by = $request->user()->id;
        if (($data['audit_status'] ?? null) === AuditStatus::Reviewed->value) {
            $product->last_verified_at = now();
        }
        $product->save();

        if ($path) {
            if ($user->isStaff()) {
                $this->mapper->place($product, BrandNode::ensurePath($path));
                $product->forceFill(['meta' => [...($product->meta ?? []), 'path_locked' => true]])->save();
            } else {
                $this->placeOrRequest($product, $path);
            }
        }
        if (array_key_exists('tags', $data)) {
            $keys = collect($data['tags'])->map(fn ($k) => explode(':', $k, 2))->filter(fn ($p) => count($p) === 2);
            $ids = $keys->map(fn ($p) => Tag::where('group', $p[0])->where('slug', $p[1])->value('id'))->filter()->all();
            $product->tags()->sync($ids);
        }

        return response()->json(['product' => new ProductResource($product->fresh(['tags', 'barcodes']))]);
    }

    /**
     * Every recipe/label on record for a product, newest first.
     *
     * @response array{versions: ProductVersionResource[]}
     */
    public function versions(Product $product): JsonResponse
    {
        return response()->json(['versions' => ProductVersionResource::collection($product->versions()->get())->resolve()]);
    }

    /**
     * A contributor's ladder: if every rung already exists (names or aliases, exact match) the product is placed there.
     * Otherwise it stays UNPLACED and keeps what they typed, verbatim, for a moderator. There is no nearest match and no
     * node is ever created on a user's behalf.
     *
     * @param  list<array{name:string}>|null  $typed
     */
    private function placeOrRequest(Product $product, ?array $typed): void
    {
        $names = array_column($typed ?? [], 'name');
        $node = $names ? BrandNode::resolvePath($names) : null;

        if ($node) {
            $this->mapper->place($product, $node);
            $product->forceFill(['requested_path' => null, 'meta' => [...($product->meta ?? []), 'path_locked' => true]])->save();

            return;
        }
        $product->forceFill([
            'brand_node_id' => null, 'path_text' => null, 'line' => null,
            'brand' => $names[0] ?? $product->brand,
            'requested_path' => $names ? implode(' > ', $names) : null,
            'search_text' => mb_strtolower(implode(' ', $names)),
        ])->save();
    }

    /** @return list<array{name:string}>|null|false null: no path given; false: too deep */
    private function segmentsFromPath(?string $path): array|null|false
    {
        return LadderPath::parse($path);
    }
}
