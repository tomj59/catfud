<?php

namespace App\Models;

use App\Enums\RatingValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rating extends Model
{
    protected $fillable = ['pet_id', 'product_id', 'rating', 'note'];

    protected function casts(): array
    {
        return ['rating' => RatingValue::class];
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
