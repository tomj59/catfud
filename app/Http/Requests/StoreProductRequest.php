<?php

namespace App\Http\Requests;

use App\Enums\ProductKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** UPC-A, EAN-13, EAN-8 or GTIN-14, with a valid check digit. */
            'gtin' => ['required', 'string'],
            'brand' => ['required_without:path', 'nullable', 'string', 'max:255'],
            /** The brand ladder, rungs separated by `>`. Users must pick existing rungs; anything else is kept as a request. */
            'path' => ['nullable', 'string', 'max:500'],
            'name' => ['required', 'string', 'max:255'],
            'species' => ['nullable', 'string', 'max:50'],
            'kind' => ['nullable', Rule::in(ProductKind::values())],
            'form' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'ingredients' => ['nullable', 'string', 'max:5000'],
            /** Label values as printed, keyed by field (e.g. `crude_protein_min_pct`). */
            'nutrition' => ['nullable', 'array'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            /** Facet tags as `group:slug` (texture, medium, life_stage, diet). */
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
        ];
    }
}
