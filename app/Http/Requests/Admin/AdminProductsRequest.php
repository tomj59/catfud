<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Every word must match brand, name, line, the typed ladder or the brand path. */
            'q' => ['nullable', 'string', 'max:200'],
            'moderation_status' => ['nullable', Rule::in(['approved', 'pending', 'needs_changes', 'rejected', 'merged'])],
            /** `no` lists products still waiting for a ladder. */
            'placed' => ['nullable', Rule::in(['yes', 'no'])],
            'created_by' => ['nullable', 'integer'],
            'barcode' => ['nullable', Rule::in(['missing', 'present'])],
            'image' => ['nullable', Rule::in(['missing', 'present'])],
            /** Limit to a brand-tree node and everything beneath it. */
            'node' => ['nullable', 'integer'],
            /** Only products carrying this facet tag (`group:slug`, e.g. `life_stage:adult-7plus`). */
            'tag' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
