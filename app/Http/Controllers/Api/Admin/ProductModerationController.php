<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminProductsRequest;
use App\Http\Requests\Admin\BulkModerateRequest;
use App\Http\Requests\Admin\MergeProductRequest;
use App\Http\Requests\Admin\ModerateProductRequest;
use App\Http\Requests\Admin\PlaceProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\AuditLog;
use App\Models\BrandNode;
use App\Models\Product;
use App\Models\Scopes\VisibleScope;
use App\Support\LadderPath;
use App\Support\ProductModerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The moderator's view of every product, whoever contributed it and whatever its state. Staff only. */
class ProductModerationController extends Controller
{
    public function __construct(private ProductModerator $moderator) {}

    /** Every product, including other people's pending and rejected ones. Newest first. */
    public function index(AdminProductsRequest $request): AnonymousResourceCollection
    {
        return ProductResource::collection($this->query($request)->orderByDesc('id')->paginate((int) $request->query('per_page', 25)));
    }

    public function show(int $id): ProductResource
    {
        return new ProductResource($this->find($id));
    }

    /** Approve, send back for changes, or decline a product. Sending back or declining needs a note the contributor can read. */
    public function moderate(ModerateProductRequest $request, int $id): JsonResponse
    {
        try {
            $product = $this->apply($this->find($id), $request->validated('action'), $request, $request->validated('note'));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return response()->json(['product' => new ProductResource($product)]);
    }

    /** Place a product on the ladder: on an existing node, or on a typed path whose missing rungs are created. */
    public function place(PlaceProductRequest $request, int $id): JsonResponse
    {
        $product = $this->find($id);
        $node = $this->nodeFrom($request->validated('node_id'), $request->validated('path'));
        if ($node instanceof JsonResponse) {
            return $node;
        }

        return response()->json(['product' => new ProductResource($this->moderator->place($product, $node))]);
    }

    /** Fold a duplicate into the approved product that survives. Stock, ratings, history and barcodes move with it. */
    public function merge(MergeProductRequest $request, int $id): JsonResponse
    {
        $survivor = Product::withoutGlobalScope(VisibleScope::class)->find($request->validated('into'));
        if (! $survivor) {
            return response()->json(['message' => 'That product does not exist.', 'errors' => ['into' => ['Unknown product.']]], 422);
        }
        try {
            $survivor = $this->moderator->mergeInto($this->find($id), $survivor, $request->user(), $request->validated('note'));
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return response()->json(['product' => new ProductResource($survivor)]);
    }

    /**
     * Apply one decision to many products. Each product succeeds or fails on its own; the response lists both.
     *
     * @response array{done: int[], failed: array<int, array{id: int, reason: string}>}
     */
    public function bulk(BulkModerateRequest $request): JsonResponse
    {
        $done = $failed = [];
        foreach (array_unique($request->validated('ids')) as $id) {
            try {
                $this->apply($this->find((int) $id), $request->validated('action'), $request, $request->validated('note'));
                $done[] = (int) $id;
            } catch (RuntimeException $e) {
                $failed[] = ['id' => (int) $id, 'reason' => $e->getMessage()];
            }
        }
        AuditLog::record('bulk_moderated', 'Product', ['action' => $request->validated('action'), 'done' => $done, 'failed' => array_column($failed, 'id')], $request->validated('note'));

        return response()->json(['done' => $done, 'failed' => $failed]);
    }

    /** Download the filtered list as CSV (same filters as the list). */
    public function export(AdminProductsRequest $request): StreamedResponse
    {
        $query = $this->query($request)->orderBy('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'gtin', 'brand', 'path', 'name', 'species', 'kind', 'form', 'moderation_status', 'audit_status', 'requested_path', 'created_by', 'created_at']);
            $query->clone()->with([])->chunkById(500, function ($rows) use ($out) {
                foreach ($rows as $p) {
                    fputcsv($out, [$p->id, $p->gtin, $p->brand, $p->path_text, $p->name, $p->species, $p->kind?->value, $p->form,
                        $p->moderation_status, $p->audit_status?->value, $p->requested_path, $p->created_by, $p->created_at?->toDateTimeString()]);
                }
            });
            fclose($out);
        }, 'catfud-products-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function query(Request $request)
    {
        $q = Product::withoutGlobalScope(VisibleScope::class)->with([]);
        foreach (preg_split('/\s+/', trim((string) $request->query('q')), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($word)).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(brand) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like])
                ->orWhereRaw('lower(line) like ?', [$like])->orWhereRaw('lower(search_text) like ?', [$like])
                ->orWhereRaw('lower(requested_path) like ?', [$like]));
        }
        $q->when($request->query('moderation_status'), fn ($b, $v) => $b->where('moderation_status', $v))
            ->when($request->query('created_by'), fn ($b, $v) => $b->where('created_by', $v))
            ->when($request->query('placed'), fn ($b, $v) => $v === 'yes' ? $b->whereNotNull('brand_node_id') : $b->whereNull('brand_node_id'))
            ->when($request->query('barcode'), fn ($b, $v) => $v === 'missing' ? $b->whereNull('gtin') : $b->whereNotNull('gtin'))
            ->when($request->query('image'), fn ($b, $v) => $v === 'missing' ? $b->whereNull('image_url') : $b->whereNotNull('image_url'));

        return $q->with(['tags', 'barcodes']);
    }

    private function apply(Product $product, string $action, Request $request, ?string $note): Product
    {
        return match ($action) {
            'approve' => $this->moderator->approve($product, $request->user(), $note),
            'needs_changes' => $this->moderator->needsChanges($product, $request->user(), (string) $note),
            'reject' => $this->moderator->reject($product, $request->user(), (string) $note),
        };
    }

    private function find(int $id): Product
    {
        return Product::withoutGlobalScope(VisibleScope::class)->findOrFail($id);
    }

    private function fail(RuntimeException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'errors' => ['product' => [$e->getMessage()]]], 422);
    }

    /** An existing node by id, or the node at the end of a typed path (creating missing rungs). A JsonResponse means invalid input. */
    private function nodeFrom(?int $nodeId, ?string $path): BrandNode|JsonResponse
    {
        if ($nodeId) {
            return BrandNode::findOrFail($nodeId);
        }
        $segments = LadderPath::parse($path);
        if (! $segments) {
            return response()->json(['message' => 'That ladder is empty or too deep.', 'errors' => ['path' => ['Invalid ladder.']]], 422);
        }

        return BrandNode::ensurePath($segments);
    }
}
