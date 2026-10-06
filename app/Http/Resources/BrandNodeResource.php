<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One rung of the brand ladder (maker, brand, line, sub-line). @mixin \App\Models\BrandNode */
class BrandNodeResource extends JsonResource
{
    /**
     * @return array{id:int, parent_id:?int, name:string, kind:?string, depth:int, aliases:?string[], species:?string[],
     *   default_tags:?string[], notes:?string, status:string, previous_status:?string, status_on:?string,
     *   status_confidence:?string, status_source:?string, status_note:?string, successor_id:?int, logo:?string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'parent_id' => $this->parent_id, 'name' => $this->name, 'kind' => $this->kind, 'depth' => $this->depth,
            'aliases' => $this->aliases, 'species' => $this->species, 'default_tags' => $this->default_tags, 'notes' => $this->notes,
            'status' => $this->status, 'previous_status' => $this->previous_status, 'status_on' => $this->status_on?->toDateString(), 'status_confidence' => $this->status_confidence,
            'status_source' => $this->status_source, 'status_note' => $this->status_note, 'successor_id' => $this->successor_id,
            'logo' => app(\App\Support\ImageStore::class)->logoUrl($this->resource),
        ];
    }
}
