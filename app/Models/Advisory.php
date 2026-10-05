<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Public Advisory: a record of what a named source said. Not a verdict and not a guarantee.
 */
class Advisory extends Model
{
    protected $fillable = ['source_name', 'source_url', 'published_at', 'ingested_at', 'source_text', 'topic'];

    protected function casts(): array
    {
        return ['published_at' => 'date:Y-m-d', 'ingested_at' => 'datetime'];
    }

    public function matches(): HasMany
    {
        return $this->hasMany(AdvisoryMatch::class);
    }
}
