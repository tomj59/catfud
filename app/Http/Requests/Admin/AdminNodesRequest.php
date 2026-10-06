<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminNodesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'parent' => ['nullable', 'integer'],
            /** `1` lists only branches with no products anywhere beneath them. */
            'empty' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['active', 'phasing_out', 'discontinued'])],
        ];
    }
}
