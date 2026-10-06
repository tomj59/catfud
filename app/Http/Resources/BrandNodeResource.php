<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One rung of the brand ladder (maker, brand, line, sub-line). @mixin \App\Models\BrandNode */
class BrandNodeResource extends JsonResource
{
    /**
     * @return array{id:int, parent_id:?int, name:string, kind:?string, depth:int, aliases:?string[], species:?string[],
     *   default_tags:?string[], notes:?string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'parent_id' => $this->parent_id, 'name' => $this->name, 'kind' => $this->kind, 'depth' => $this->depth,
            'aliases' => $this->aliases, 'species' => $this->species, 'default_tags' => $this->default_tags, 'notes' => $this->notes,
        ];
    }
}
