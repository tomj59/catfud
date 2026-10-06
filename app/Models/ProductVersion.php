<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One recipe/label of a product as observed at a point in time. See the product_versions migration. */
class ProductVersion extends Model
{
    protected $fillable = ['product_id', 'version', 'name', 'title_as_listed', 'ingredients', 'nutrition', 'is_current',
        'source', 'observed_by', 'observed_at', 'note'];

    protected function casts(): array
    {
        return ['nutrition' => 'array', 'is_current' => 'boolean', 'observed_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
