<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** The picture on file for a product, and where it came from. */
class ProductImage extends Model
{
    protected $fillable = ['product_id', 'disk', 'path', 'mime', 'bytes', 'width', 'height', 'source', 'source_url', 'licence', 'attribution', 'uploaded_by'];

    public const SOURCES = ['manufacturer', 'retailer', 'own_photo', 'other'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }
}
