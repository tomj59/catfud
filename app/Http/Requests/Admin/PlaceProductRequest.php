<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlaceProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** An existing node to place the product on. */
            'node_id' => ['required_without:path', 'nullable', 'integer', 'exists:brand_nodes,id'],
            /** Or a ladder ("Purina > Pro Plan > Prime Plus"); rungs that do not exist yet are created. */
            'path' => ['required_without:node_id', 'nullable', 'string', 'max:500'],
        ];
    }
}
