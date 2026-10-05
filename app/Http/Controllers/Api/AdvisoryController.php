<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Models\Advisory;
use App\Models\AdvisoryMatch;
use App\Support\AdvisoryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdvisoryController extends Controller
{
    /**
     * Public Advisories that may relate to what is in the user's pantry (matches limited to pantry products).
     * `?all=1` lists every advisory with all of its matches. Every item is attributed to its source.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $all = $request->boolean('all');
        $pantry = $user->inventoryItems()->pluck('product_id');

        $query = Advisory::with(['matches.product', 'matches.reviews'])->orderByDesc('published_at')->orderByDesc('id');

        if (! $all) {
            $query->whereHas('matches', fn ($m) => $m->whereIn('product_id', $pantry));
        }

        $advisories = $query->get()->map(fn ($a) => AdvisoryPresenter::advisory($a, $user, $all ? null : $pantry))->values();

        return response()->json([
            'advisories' => $advisories,
            'disclaimer' => config('catfud.advisory_disclaimer'),
        ]);
    }

    /** The user's own decision about one match: new | confirmed | dismissed, with an optional note. The advisory is untouched. */
    public function review(Request $request, int $id): JsonResponse
    {
        $match = AdvisoryMatch::with(['advisory', 'product'])->findOrFail($id);

        $data = $request->validate([
            'status' => ['required', Rule::in(ReviewStatus::values())],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $request->user()->advisoryMatchReviews()->updateOrCreate(
            ['advisory_match_id' => $match->id],
            ['status' => $data['status'], 'note' => $data['note'] ?? null],
        );

        $match->load('reviews');

        return response()->json(['match' => AdvisoryPresenter::match($match, $request->user())]);
    }
}
