<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BrandNode;
use App\Models\Product;
use App\Models\Scopes\VisibleScope;
use Illuminate\Http\JsonResponse;

/** The numbers that tell a moderator what needs attention. */
class DashboardController extends Controller
{
    /**
     * @response array{
     *   moderation: array{pending: int, pending_unplaced: int, needs_changes: int, rejected: int, oldest_unplaced_at: ?string},
     *   catalogue: array{approved: int, without_image: int, without_barcode: int, unreviewed: int},
     *   tree: array{nodes: int, empty_nodes: int, inactive_nodes: int, near_duplicates: array<int, array{a: array{id: int, name: string}, b: array{id: int, name: string}, parent: ?string}>}
     * }
     */
    public function __invoke(): JsonResponse
    {
        $all = fn () => Product::withoutGlobalScope(VisibleScope::class)->with([]);
        $approved = fn () => $all()->where('moderation_status', 'approved');

        $nodes = BrandNode::orderBy('path_key')->get();
        $used = $all()->whereNotNull('brand_node_id')->where('moderation_status', '!=', 'merged')->distinct()->pluck('brand_node_id')->flip();
        $usedKeys = $nodes->filter(fn ($n) => isset($used[$n->id]))->pluck('path_key');
        $empty = $nodes->filter(fn ($n) => ! $usedKeys->contains(fn ($k) => $k === $n->path_key || str_starts_with($k, $n->path_key.'>')))->count();

        return response()->json([
            'moderation' => [
                'pending' => $all()->where('moderation_status', 'pending')->count(),
                'pending_unplaced' => $all()->where('moderation_status', 'pending')->whereNull('brand_node_id')->count(),
                'needs_changes' => $all()->where('moderation_status', 'needs_changes')->count(),
                'rejected' => $all()->where('moderation_status', 'rejected')->count(),
                'oldest_unplaced_at' => $all()->where('moderation_status', 'pending')->whereNull('brand_node_id')->min('created_at')
                    ? \Illuminate\Support\Carbon::parse($all()->where('moderation_status', 'pending')->whereNull('brand_node_id')->min('created_at'))->toISOString() : null,
            ],
            'catalogue' => [
                'approved' => $approved()->count(),
                'without_image' => $approved()->whereNull('image_url')->count(),
                'without_barcode' => $approved()->whereNull('gtin')->count(),
                'unreviewed' => $approved()->where('audit_status', 'unreviewed')->count(),
            ],
            'tree' => [
                'nodes' => $nodes->count(),
                'empty_nodes' => $empty,
                'inactive_nodes' => $nodes->where('status', '!=', 'active')->count(),
                'near_duplicates' => $this->nearDuplicates($nodes),
            ],
        ]);
    }

    /** Sibling nodes whose names differ by a typo or punctuation ("Pro Plan" / "ProPlan" / "Pro Plann"). @return list<array<string,mixed>> */
    private function nearDuplicates($nodes): array
    {
        $out = [];
        foreach ($nodes->groupBy('parent_id') as $parentId => $siblings) {
            $norm = $siblings->map(fn ($n) => [$n, preg_replace('/[^a-z0-9]/', '', mb_strtolower($n->name))])->values();
            for ($i = 0; $i < $norm->count(); $i++) {
                for ($j = $i + 1; $j < $norm->count(); $j++) {
                    [$a, $x] = $norm[$i];
                    [$b, $y] = $norm[$j];
                    if ($x === '' || $y === '') {
                        continue;
                    }
                    if ($x === $y || (min(strlen($x), strlen($y)) >= 5 && levenshtein($x, $y) <= 1)) {
                        $out[] = ['a' => ['id' => $a->id, 'name' => $a->name], 'b' => ['id' => $b->id, 'name' => $b->name],
                            'parent' => $parentId ? $nodes->firstWhere('id', $parentId)?->name : null];
                    }
                }
            }
        }

        return array_slice($out, 0, 50);
    }
}
