<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A facet value: texture, medium, life stage or diet. @mixin \App\Models\Tag */
class TagResource extends JsonResource
{
    /** @return array{id:int, group:string, slug:string, label:string} */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'group' => $this->group, 'slug' => $this->slug, 'label' => $this->label];
    }
}
