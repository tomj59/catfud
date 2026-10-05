<?php

namespace App\Models;

use App\Enums\MatchBasis;
use App\Enums\MatchConfidence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdvisoryMatch extends Model
{
    protected $fillable = ['advisory_id', 'product_id', 'match_basis', 'confidence', 'match_detail'];

    protected function casts(): array
    {
        return ['match_basis' => MatchBasis::class, 'confidence' => MatchConfidence::class];
    }

    public function advisory(): BelongsTo
    {
        return $this->belongsTo(Advisory::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(AdvisoryMatchReview::class);
    }
}
