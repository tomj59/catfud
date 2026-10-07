<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** The picture on file for a product, and where it came from. */
class ProductImage extends Model
{
    protected $fillable = ['product_id', 'disk', 'path', 'mime', 'bytes', 'width', 'height', 'source', 'source_url', 'licence', 'attribution', 'uploaded_by',
        'key', 'origin_url', 'status', 'fetched_at', 'fail_count', 'last_error'];

    protected $casts = ['fetched_at' => 'datetime'];

    public const SOURCES = ['manufacturer', 'retailer', 'own_photo', 'other'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** A mirrored picture is always served through our own keyed endpoint; an uploaded one straight from the image disk. */
    public function url(): string
    {
        return $this->key ? '/api/v1/img/'.$this->key : Storage::disk($this->disk)->url($this->path);
    }

    /** True when another product row points at the same stored file (products that share one picture share one file). */
    public function fileSharedWithOthers(): bool
    {
        return $this->path !== null
            && static::where('disk', $this->disk)->where('path', $this->path)->where('id', '!=', $this->id)->exists();
    }

    /** Deletes the stored file unless another product still uses it. */
    public function deleteFile(): void
    {
        if ($this->path && ! $this->fileSharedWithOthers()) {
            Storage::disk($this->disk)->delete($this->path);
        }
    }
}
