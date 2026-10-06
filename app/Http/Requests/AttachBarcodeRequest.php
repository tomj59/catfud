<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttachBarcodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'gtin' => ['required', 'string'],
            /** For an extra barcode on a product that already has one: "24-can case". */
            'pack_label' => ['nullable', 'string', 'max:100'],
        ];
    }
}
