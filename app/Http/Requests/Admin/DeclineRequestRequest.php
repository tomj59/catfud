<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeclineRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'requested_path' => ['required', 'string', 'max:500'],
            /** Shown to each contributor. */
            'note' => ['required', 'string', 'max:2000'],
        ];
    }
}
