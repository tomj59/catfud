<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RetireNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(\App\Enums\NodeStatus::values())],
            'status_on' => ['nullable', 'date'],
            'confidence' => ['nullable', Rule::in(['confirmed', 'reported', 'rumoured'])],
            /** Where this was learned: a URL or a short note. */
            'source' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:5000'],
            /** The node that replaces this one, so shoppers can be pointed to it. */
            'successor_id' => ['nullable', 'integer', 'exists:brand_nodes,id'],
            /** Report what would be affected without changing anything. */
            'preview' => ['nullable', 'boolean'],
        ];
    }
}
