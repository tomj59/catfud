<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BrandNodesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The parent node to list the children of; omit for the top level. */
            'parent' => ['nullable', 'integer'],
            'form' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', Rule::in(['missing', 'present'])],
        ];
    }
}
