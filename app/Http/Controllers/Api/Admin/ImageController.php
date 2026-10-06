<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UploadLogoRequest;
use App\Http\Requests\Admin\UploadProductImageRequest;
use App\Http\Resources\BrandNodeResource;
use App\Http\Resources\ProductResource;
use App\Models\BrandNode;
use App\Models\Product;
use App\Models\Scopes\VisibleScope;
use App\Support\ImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Product pictures (with their source and licence) and brand logos. Staff only. */
class ImageController extends Controller
{
    public function __construct(private ImageStore $images) {}

    /** Set or replace a product's picture. The previous file is deleted; the new one becomes `image_url`. */
    public function storeProductImage(UploadProductImageRequest $request, int $id): JsonResponse
    {
        $product = Product::withoutGlobalScope(VisibleScope::class)->findOrFail($id);
        $this->images->setProductImage($product, $request->file('image'), $request->safe()->except('image'), $request->user());

        return response()->json(['product' => new ProductResource($this->fresh($id))]);
    }

    public function destroyProductImage(Request $request, int $id): JsonResponse
    {
        $product = Product::withoutGlobalScope(VisibleScope::class)->findOrFail($id);
        $this->images->removeProductImage($product, $request->user());

        return response()->json(['product' => new ProductResource($this->fresh($id))]);
    }

    /** Upload a brand, line or manufacturer logo. It replaces any built-in logo shown in the picker. */
    public function storeLogo(UploadLogoRequest $request, int $id): JsonResponse
    {
        $node = $this->images->setNodeLogo(BrandNode::findOrFail($id), $request->file('logo'));

        return response()->json(['node' => new BrandNodeResource($node)]);
    }

    public function destroyLogo(int $id): JsonResponse
    {
        $node = BrandNode::findOrFail($id);
        $this->images->removeNodeLogo($node);

        return response()->json(['node' => new BrandNodeResource($node->fresh())]);
    }

    private function fresh(int $id): Product
    {
        return Product::withoutGlobalScope(VisibleScope::class)->findOrFail($id);
    }
}
