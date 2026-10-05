<?php

namespace App\Models\Concerns;

use App\Models\Scopes\RegionScope;
use App\Support\Region;

trait BelongsToRegion
{
    public static function bootBelongsToRegion(): void
    {
        static::addGlobalScope(new RegionScope);
        static::creating(function ($model) {
            $model->region ??= Region::current();
        });
    }
}
