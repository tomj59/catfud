<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A catalogue product as the app and the admin tool see it. Import bookkeeping and audit fields are staff-only.
 *
 * @mixin \App\Models\Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array{
     *   id:int, region:string, gtin:?string, upc_a:?string, has_barcode:bool,
     *   placed:bool, brand:string, name:string, species:string, kind:string, form:?string, description:?string,
     *   ingredients:?string, nutrition:?array<string,mixed>, image_url:?string, source:?string,
     *   last_verified_at:?string, line:?string, texture:?string, source_url:?string,
     *   brand_node_id:?int, path_text:?string, title_as_listed:?string, requested_path:?string,
     *   moderation_status:string, review_note:?string, reviewed_at:?string,
     *   formula_version:int, formula_changed_at:?string,
     *   tags:array<int,array{id:int, group:string, slug:string, label:string}>,
     *   barcodes:array<int,array{id:int, gtin:string, upc_a:?string, pack_label:?string}>,
     *   audit_status?:string, audit_notes?:?string, import_key?:?string, meta?:?array<string,mixed>,
     *   created_by?:?int, reviewed_by?:?int, merged_into_id?:?int,
     *   created_at:?string, updated_at:?string
     * }
     */
    public function toArray(Request $request): array
    {
        $staff = (bool) $request->user()?->isStaff();

        return [
            'id' => $this->id,
            'region' => $this->region,
            'gtin' => $this->gtin,
            'upc_a' => $this->upc_a,
            'has_barcode' => $this->has_barcode,
            'placed' => $this->placed,
            'brand' => $this->brand,
            'name' => $this->name,
            'species' => $this->species,
            'kind' => $this->kind?->value,
            'form' => $this->form,
            'description' => $this->description,
            'ingredients' => $this->ingredients,
            'nutrition' => $this->nutrition,
            'image_url' => $this->image_url,
            'source' => $this->source,
            'last_verified_at' => $this->last_verified_at?->toISOString(),
            'line' => $this->line,
            'texture' => $this->texture,
            'source_url' => $this->source_url,
            'brand_node_id' => $this->brand_node_id,
            'path_text' => $this->path_text,
            'title_as_listed' => $this->title_as_listed,
            'requested_path' => $this->requested_path,
            'moderation_status' => $this->moderation_status,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'formula_version' => $this->formula_version,
            'formula_changed_at' => $this->formula_changed_at?->toISOString(),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'barcodes' => ProductBarcodeResource::collection($this->whenLoaded('barcodes')),
            $this->mergeWhen($staff, [
                'audit_status' => $this->audit_status?->value,
                'audit_notes' => $this->audit_notes,
                'import_key' => $this->import_key,
                'meta' => $this->meta,
                'created_by' => $this->created_by,
                'reviewed_by' => $this->reviewed_by,
                'merged_into_id' => $this->merged_into_id,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
