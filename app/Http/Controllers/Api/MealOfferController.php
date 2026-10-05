<?php

namespace App\Http\Controllers\Api;

use App\Enums\MealOutcome;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MealOfferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $offers = $request->user()->mealOffers()->with('product')
            ->when($request->query('pet_id'), fn ($q, $id) => $q->where('pet_id', $id))
            ->latest('offered_at')->limit(100)->get();

        return response()->json(['meal_offers' => $offers]);
    }

    /** Record what was offered. The outcome can be sent now or filled in afterwards with PATCH. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pet_id' => ['required', 'integer', Rule::exists('pets', 'id')->where('user_id', $request->user()->id)],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'offered_at' => ['nullable', 'date'],
            'outcome' => ['nullable', Rule::in(MealOutcome::values())],
            'suggested_by_app' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $offer = $request->user()->mealOffers()->create([
            ...$data,
            'offered_at' => $data['offered_at'] ?? now(),
            'suggested_by_app' => $data['suggested_by_app'] ?? false,
        ]);

        return response()->json(['meal_offer' => $offer->load('product')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $offer = $request->user()->mealOffers()->findOrFail($id);

        $offer->update($request->validate([
            'outcome' => ['required', Rule::in(MealOutcome::values())],
            'note' => ['nullable', 'string', 'max:2000'],
        ]));

        return response()->json(['meal_offer' => $offer->load('product')]);
    }
}
