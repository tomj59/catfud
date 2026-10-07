<?php

namespace App\Support;

use App\Models\BrandNode;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Scopes\VisibleScope;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Saves product pictures and brand logos on the image disk (config catfud.image_disk), so moving to S3 later is a config change. */
class ImageStore
{
    public static function disk(): string
    {
        return (string) config('catfud.image_disk', 'public');
    }

    /** @param array{source:string, source_url?:?string, licence?:?string, attribution?:?string} $meta */
    public function setProductImage(Product $product, UploadedFile $file, array $meta, User $by): ProductImage
    {
        $disk = self::disk();
        $old = ProductImage::where('product_id', $product->id)->first();
        $path = $file->storeAs('products/'.intdiv($product->id, 1000), $product->id.'-'.Str::lower(Str::random(8)).'.'.$this->ext($file), $disk);
        [$w, $h] = @getimagesize($file->getRealPath()) ?: [null, null];

        $image = ProductImage::updateOrCreate(['product_id' => $product->id], [
            'disk' => $disk, 'path' => $path, 'mime' => $file->getMimeType(), 'bytes' => $file->getSize(), 'width' => $w, 'height' => $h,
            'source' => $meta['source'], 'source_url' => $meta['source_url'] ?? null, 'licence' => $meta['licence'] ?? null,
            'attribution' => $meta['attribution'] ?? null, 'uploaded_by' => $by->id,
            'key' => null, 'origin_url' => null, 'status' => 'ready', 'fetched_at' => null, 'fail_count' => 0, 'last_error' => null,
        ]);
        if ($old && $old->path && $old->path !== $path && ! ProductImage::where('disk', $old->disk)->where('path', $old->path)->exists()) {
            Storage::disk($old->disk)->delete($old->path);
        }
        $this->touch($product, $image->url(), $by);

        return $image;
    }

    public function removeProductImage(Product $product, User $by): bool
    {
        $image = ProductImage::where('product_id', $product->id)->first();
        if (! $image) {
            return false;
        }
        $image->delete();
        if ($image->path && ! ProductImage::where('disk', $image->disk)->where('path', $image->path)->exists()) {
            Storage::disk($image->disk)->delete($image->path);
        }
        $this->touch($product, null, $by);

        return true;
    }

    public function setNodeLogo(BrandNode $node, UploadedFile $file): BrandNode
    {
        $disk = self::disk();
        $old = $node->logo_path;
        $path = $file->storeAs('brands', Str::slug(str_replace("'", '', $node->name)).'-'.Str::lower(Str::random(6)).'.'.$this->ext($file), $disk);
        $node->forceFill(['logo_path' => $path])->save();
        if ($old) {
            Storage::disk($disk)->delete($old);
        }

        return $node->fresh();
    }

    public function removeNodeLogo(BrandNode $node): void
    {
        if ($node->logo_path) {
            Storage::disk(self::disk())->delete($node->logo_path);
            $node->forceFill(['logo_path' => null])->save();
        }
    }

    public function logoUrl(BrandNode $node): ?string
    {
        return $node->logo_path ? Storage::disk(self::disk())->url($node->logo_path) : null;
    }

    private function ext(UploadedFile $file): string
    {
        return match ($file->getMimeType()) { 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg' };
    }

    private function touch(Product $product, ?string $url, User $by): void
    {
        $fresh = Product::withoutGlobalScope(VisibleScope::class)->find($product->id);
        $fresh->forceFill(['image_url' => $url, 'last_edited_by' => $by->id])->save();   // audited like any other edit
    }
}
