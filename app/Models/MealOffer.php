<?php

namespace App\Models;

use App\Enums\MealOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealOffer extends Model
{
    protected $fillable = ['pet_id', 'product_id', 'offered_at', 'outcome', 'suggested_by_app', 'note'];

    protected function casts(): array
    {
        return [
            'offered_at' => 'datetime',
            'outcome' => MealOutcome::class,
            'suggested_by_app' => 'boolean',
        ];
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
