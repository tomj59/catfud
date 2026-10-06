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
            'status' => ['nullable', Rule::in(\App\Enums\NodeStatus::values())],
            /** Rungs that are, or ever were, in this status (e.g. `retired` also finds a retired rung since switched to disabled). */
            'ever' => ['nullable', Rule::in(\App\Enums\NodeStatus::values())],
        ];
    }
}
