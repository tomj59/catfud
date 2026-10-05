<?php

namespace App\Models;

use App\Enums\AuditStatus;
use App\Enums\ProductKind;
use App\Models\Concerns\BelongsToRegion;
use App\Support\Gtin;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use BelongsToRegion;

    protected $with = ['tags', 'barcodes'];

    protected $fillable = [
        'gtin', 'brand', 'name', 'species', 'kind', 'form', 'description', 'ingredients',
        'nutrition', 'image_url', 'source', 'last_verified_at', 'created_by',
        'import_key', 'line', 'texture', 'source_url', 'meta', 'audit_status', 'audit_notes', 'last_edited_by',
        'region', 'brand_node_id', 'path_text', 'search_text', 'title_as_listed',
    ];

    protected $hidden = ['search_text'];

    protected $appends = ['upc_a', 'has_barcode'];

    protected function casts(): array
    {
        return [
            'kind' => ProductKind::class,
            'audit_status' => AuditStatus::class,
            'nutrition' => 'array',
            'meta' => 'array',
            'last_verified_at' => 'datetime',
        ];
    }

    /** The 12-digit UPC-A form of the stored GTIN-13, when it has one. */
    public function getUpcAAttribute(): ?string
    {
        return $this->gtin ? Gtin::toUpcA($this->gtin) : null;
    }

    /** Find a product by any scanned or typed code (UPC-A, EAN-13, EAN-8, GTIN-14 with leading 0). */
    public static function findByCode(string $code): ?self
    {
        $gtin = Gtin::normalize($code);
        if (! $gtin) {
            return null;
        }

        return static::where('gtin', $gtin)->first()
            ?? static::whereHas('barcodes', fn ($q) => $q->where('gtin', $gtin))->first();
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(BrandNode::class, 'brand_node_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('group')->orderBy('sort');
    }

    public function barcodes()
    {
        return $this->hasMany(ProductBarcode::class);
    }

    /** Has someone scanned a real package and wired up a barcode yet? */
    public function getHasBarcodeAttribute(): bool
    {
        return $this->gtin !== null;
    }

    /** Seeded rows that a person has not touched yet; only these may be refreshed by a re-import. */
    public function isUntouched(): bool
    {
        return $this->gtin === null && $this->audit_status === AuditStatus::Unreviewed && $this->last_edited_by === null;
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }
}
