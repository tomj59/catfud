<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeclineRequestRequest;
use App\Http\Requests\Admin\ResolveRequestRequest;
use App\Models\AuditLog;
use App\Models\BrandNode;
use App\Models\Product;
use App\Models\Scopes\VisibleScope;
use App\Support\LadderPath;
use App\Support\ProductModerator;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Products whose ladder does not exist yet, grouped by what people typed. One decision settles a whole group: map it to a
 * node (creating the missing rungs if it is genuinely new), or decline it. The groups with the most people behind them,
 * then the oldest, come first.
 */
class RequestQueueController extends Controller
{
    public function __construct(private ProductModerator $moderator) {}

    /**
     * @response array{groups: array<int, array{requested_path: string, products: int, contributors: int, with_barcode: int, oldest_at: ?string, product_ids: int[]}>}
     */
    public function index(): JsonResponse
    {
        $groups = $this->pending()->get()->groupBy(fn (Product $p) => mb_strtolower(trim((string) $p->requested_path)))
            ->map(fn ($g) => [
                'requested_path' => (string) $g->first()->requested_path,
                'products' => $g->count(),
                'contributors' => $g->pluck('created_by')->unique()->count(),
                'with_barcode' => $g->whereNotNull('gtin')->count(),
                'oldest_at' => $g->min('created_at')?->toISOString(),
                'product_ids' => $g->pluck('id')->take(50)->values()->all(),
            ])->sortBy([['contributors', 'desc'], ['oldest_at', 'asc']])->values()->all();

        return response()->json(['groups' => $groups]);
    }

    /**
     * Place every product in a group on one node. Add `approve` to make them public in the same step; products that cannot
     * be approved (a barcode already in the public catalogue) stay placed and are listed under `failed` for merging.
     *
     * @response array{placed: int, approved: int, failed: array<int, array{id: int, reason: string}>}
     */
    public function resolve(ResolveRequestRequest $request): JsonResponse
    {
        $products = $this->group($request->validated('requested_path'));
        if ($products->isEmpty()) {
            return response()->json(['message' => 'No waiting products have that ladder.', 'errors' => ['requested_path' => ['Not in the queue.']]], 404);
        }

        if ($nodeId = $request->validated('node_id')) {
            $node = BrandNode::findOrFail($nodeId);
        } else {
            $segments = LadderPath::parse($request->validated('path'));
            if (! $segments) {
                return response()->json(['message' => 'That ladder is empty or too deep.', 'errors' => ['path' => ['Invalid ladder.']]], 422);
            }
            $node = BrandNode::ensurePath($segments);
        }

        $approved = 0;
        $failed = [];
        foreach ($products as $product) {
            $product = $this->moderator->place($product, $node);
            if ($request->boolean('approve')) {
                try {
                    $this->moderator->approve($product, $request->user());
                    $approved++;
                } catch (RuntimeException $e) {
                    $failed[] = ['id' => $product->id, 'reason' => $e->getMessage()];
                }
            }
        }
        AuditLog::record('request_resolved', 'Product', ['requested_path' => $request->validated('requested_path'), 'node_id' => $node->id, 'products' => $products->pluck('id')->all()]);

        return response()->json(['placed' => $products->count(), 'approved' => $approved, 'failed' => $failed]);
    }

    /**
     * Decline a whole group, with a reason each contributor can read.
     *
     * @response array{declined: int}
     */
    public function decline(DeclineRequestRequest $request): JsonResponse
    {
        $products = $this->group($request->validated('requested_path'));
        foreach ($products as $product) {
            $this->moderator->reject($product, $request->user(), $request->validated('note'));
        }
        AuditLog::record('request_declined', 'Product', ['requested_path' => $request->validated('requested_path'), 'products' => $products->pluck('id')->all()], $request->validated('note'));

        return response()->json(['declined' => $products->count()]);
    }

    private function pending()
    {
        return Product::withoutGlobalScope(VisibleScope::class)->with([])->whereNull('brand_node_id')->where('moderation_status', 'pending')
            ->whereNotNull('requested_path');
    }

    /** @return \Illuminate\Support\Collection<int, Product> */
    private function group(string $requestedPath)
    {
        return $this->pending()->get()->filter(fn (Product $p) => mb_strtolower(trim((string) $p->requested_path)) === mb_strtolower(trim($requestedPath)))->values();
    }
}
