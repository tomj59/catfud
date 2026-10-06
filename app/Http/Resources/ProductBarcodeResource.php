<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** An extra barcode (another pack size, multipack or case). @mixin \App\Models\ProductBarcode */
class ProductBarcodeResource extends JsonResource
{
    /** @return array{id:int, gtin:string, upc_a:?string, pack_label:?string} */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'gtin' => $this->gtin, 'upc_a' => $this->upc_a, 'pack_label' => $this->pack_label];
    }
}
