<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRegion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An extra barcode for a product: another pack size, multipack or case. The first barcode stays on products.gtin. */
class ProductBarcode extends Model
{
    use BelongsToRegion;

    protected $fillable = ['product_id', 'region', 'gtin', 'pack_label', 'added_by'];

    protected $appends = ['upc_a'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getUpcAAttribute(): ?string
    {
        return \App\Support\Gtin::toUpcA($this->gtin);
    }
}
