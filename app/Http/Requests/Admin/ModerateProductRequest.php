<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModerateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['approve', 'needs_changes', 'reject'])],
            /** Required when sending a product back or declining it; the contributor can read it. */
            'note' => ['required_if:action,needs_changes,reject', 'nullable', 'string', 'max:2000'],
        ];
    }
}
