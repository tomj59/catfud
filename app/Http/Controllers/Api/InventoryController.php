<?php

namespace App\Http\Controllers\Api;

use App\Enums\InventoryStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Gtin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    /** The user's own inventory only. ?status=low|out|stocked filters. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(InventoryStatus::values())]]);

        $items = $request->user()->inventoryItems()->with('product')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest('updated_at')->get();

        return response()->json(['items' => $items]);
    }

    /**
     * Add a product to the user's inventory by product_id or by a scanned/typed code.
     * If it is already there, the quantity is added to (one row per user per product).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required_without:gtin', 'nullable', 'integer'],
            'gtin' => ['required_without:product_id', 'nullable', 'string'],
            'quantity' => ['nullable', 'numeric', 'gt:0', 'max:99999'],
            'unit' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', Rule::in(InventoryStatus::values())],
            'opened_at' => ['nullable', 'date'],
        ]);

        $product = isset($data['product_id'])
            ? Product::find($data['product_id'])
            : (Gtin::isValid($data['gtin'] ?? null) ? Product::findByCode($data['gtin']) : null);

        if (! $product) {
            return response()->json(['message' => 'Product not found. Look it up or add it first.'], 404);
        }

        $item = $request->user()->inventoryItems()->where('product_id', $product->id)->first();
        $created = false;

        if ($item) {
            $item->quantity = $item->quantity + ($data['quantity'] ?? 1);
            $item->status = $data['status'] ?? InventoryStatus::Stocked->value;
            $item->save();
        } else {
            $created = true;
            $item = $request->user()->inventoryItems()->create([
                'product_id' => $product->id,
                'quantity' => $data['quantity'] ?? 1,
                'unit' => $data['unit'] ?? 'unit',
                'status' => $data['status'] ?? InventoryStatus::Stocked->value,
                'opened_at' => $data['opened_at'] ?? null,
            ]);
        }

        return response()->json(['item' => $item->load('product')], $created ? 201 : 200);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $item = $request->user()->inventoryItems()->findOrFail($id);

        $item->update($request->validate([
            'quantity' => ['sometimes', 'numeric', 'min:0', 'max:99999'],
            'unit' => ['sometimes', 'string', 'max:50'],
            'status' => ['sometimes', Rule::in(InventoryStatus::values())],
            'opened_at' => ['nullable', 'date'],
        ]));

        return response()->json(['item' => $item->load('product')]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->user()->inventoryItems()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
