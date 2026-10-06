<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Omit for a new top-level manufacturer or brand. */
            'parent_id' => ['nullable', 'integer', 'exists:brand_nodes,id'],
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['nullable', Rule::in(['manufacturer', 'brand', 'line', 'subline'])],
            'species' => ['nullable', 'array', 'max:10'],
            'species.*' => ['string', 'max:30'],
            'aliases' => ['nullable', 'array', 'max:30'],
            'aliases.*' => ['string', 'max:255'],
        ];
    }
}
