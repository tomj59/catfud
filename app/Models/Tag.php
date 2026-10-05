<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A facet value (texture, medium, life stage, diet). Vocabulary rows, not parts of a product name. */
class Tag extends Model
{
    public $timestamps = false;

    protected $fillable = ['group', 'slug', 'label', 'sort'];

    protected $hidden = ['pivot', 'sort'];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }
}
