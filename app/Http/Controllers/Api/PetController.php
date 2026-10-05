<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['pets' => $request->user()->pets()->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $pet = $request->user()->pets()->create($this->validated($request))->fresh(); // fresh() so DB defaults (species) show

        return response()->json(['pet' => $pet], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $pet = $request->user()->pets()->findOrFail($id);
        $pet->update($this->validated($request, partial: true));

        return response()->json(['pet' => $pet]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->user()->pets()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'species' => ['sometimes', 'string', 'max:50'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
