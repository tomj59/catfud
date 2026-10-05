<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\MealSuggester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuggestionController extends Controller
{
    /**
     * GET /pets/{id}/suggestions?shuffle=1&exclude[]=3&exclude[]=7&seed=42
     * The pick and the evidence behind it. `exclude` = products the user already passed on; `seed` makes shuffle repeatable.
     */
    public function show(Request $request, int $id, MealSuggester $suggester): JsonResponse
    {
        $pet = $request->user()->pets()->findOrFail($id);

        $data = $request->validate([
            'shuffle' => ['nullable', 'boolean'],
            'exclude' => ['nullable', 'array', 'max:100'],
            'exclude.*' => ['integer'],
            'seed' => ['nullable', 'integer'],
        ]);

        return response()->json($suggester->suggest(
            $pet,
            (bool) ($data['shuffle'] ?? false),
            array_map('intval', $data['exclude'] ?? []),
            isset($data['seed']) ? (int) $data['seed'] : null,
        ));
    }
}
