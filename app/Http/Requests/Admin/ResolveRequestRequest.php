<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The ladder text exactly as listed in the queue. */
            'requested_path' => ['required', 'string', 'max:500'],
            'node_id' => ['required_without:path', 'nullable', 'integer', 'exists:brand_nodes,id'],
            'path' => ['required_without:node_id', 'nullable', 'string', 'max:500'],
            /** Also approve the products once placed. */
            'approve' => ['nullable', 'boolean'],
        ];
    }
}
