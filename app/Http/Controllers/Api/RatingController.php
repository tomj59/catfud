<?php

namespace App\Http\Controllers\Api;

use App\Enums\RatingValue;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RatingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $ratings = $request->user()->ratings()->with('product')
            ->when($request->query('pet_id'), fn ($q, $id) => $q->where('pet_id', $id))
            ->latest('updated_at')->get();

        return response()->json(['ratings' => $ratings]);
    }

    /** One rating per pet per product: posting again replaces the earlier one. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pet_id' => ['required', 'integer', Rule::exists('pets', 'id')->where('user_id', $request->user()?->id)],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'rating' => ['required', Rule::in(RatingValue::values())],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $rating = $request->user()->ratings()->updateOrCreate(
            ['pet_id' => $data['pet_id'], 'product_id' => $data['product_id']],
            ['rating' => $data['rating'], 'note' => $data['note'] ?? null],
        );

        return response()->json(['rating' => $rating->load('product')], $rating->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->user()->ratings()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
