<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One recipe/label of a product at a point in time. @mixin \App\Models\ProductVersion */
class ProductVersionResource extends JsonResource
{
    /**
     * @return array{id:int, version:int, name:string, title_as_listed:?string, ingredients:?string,
     *   nutrition:?array<string,mixed>, is_current:bool, source:?string, observed_at:?string, note:?string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'version' => $this->version, 'name' => $this->name, 'title_as_listed' => $this->title_as_listed,
            'ingredients' => $this->ingredients, 'nutrition' => $this->nutrition, 'is_current' => $this->is_current,
            'source' => $this->source, 'observed_at' => $this->observed_at?->toISOString(), 'note' => $this->note,
        ];
    }
}
