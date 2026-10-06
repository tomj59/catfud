<?php

namespace App\Http\Requests\Admin;

use App\Models\ProductImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** JPEG, PNG or WebP, up to 5 MB. */
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=200,min_height=200'],
            /** Where the picture came from. */
            'source' => ['required', Rule::in(ProductImage::SOURCES)],
            'source_url' => ['nullable', 'url', 'max:2048'],
            /** The licence or the permission it was used under. */
            'licence' => ['nullable', 'string', 'max:255'],
            /** A credit line to show with it, if one is required. */
            'attribution' => ['nullable', 'string', 'max:255'],
        ];
    }
}
