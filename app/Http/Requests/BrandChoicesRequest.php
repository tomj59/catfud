<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BrandChoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The ladder chosen so far, rungs separated by `>` (e.g. `Purina > Pro Plan`); omit for the top level. */
            'path' => ['nullable', 'string', 'max:500'],
            /** Hide names sold only for other species (e.g. `cat`). */
            'species' => ['nullable', 'string', 'max:30'],
        ];
    }
}
