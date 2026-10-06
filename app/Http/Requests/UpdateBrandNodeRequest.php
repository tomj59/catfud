<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBrandNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Renaming keeps the old name as an alias. */
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            /** Move under another node; `null` makes it a top-level node. */
            'parent_id' => ['sometimes', 'nullable', 'integer'],
            'kind' => ['sometimes', 'nullable', Rule::in(['manufacturer', 'brand', 'line', 'subline'])],
            'aliases' => ['sometimes', 'nullable', 'array', 'max:30'],
            'aliases.*' => ['string', 'max:255'],
            /** Species this is sold for (`cat`, `dog`); `null` or empty means all. */
            'species' => ['sometimes', 'nullable', 'array', 'max:10'],
            'species.*' => ['string', 'max:30'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
