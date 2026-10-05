<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvisoryMatchReview extends Model
{
    protected $fillable = ['advisory_match_id', 'status', 'note'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['status' => ReviewStatus::class];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(AdvisoryMatch::class, 'advisory_match_id');
    }
}
