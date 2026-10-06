<?php

namespace App\Http\Requests;

use App\Enums\AuditStatus;
use App\Enums\ProductKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // who may edit which product depends on the product; the controller enforces it
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            /** Move the product within the brand tree (`Purina > Pro Plan > Prime Plus`). */
            'path' => ['nullable', 'string', 'max:500'],
            'texture' => ['nullable', 'string', 'max:255'],
            'form' => ['nullable', 'string', 'max:100'],
            'kind' => ['sometimes', Rule::in(ProductKind::values())],
            'description' => ['nullable', 'string', 'max:5000'],
            'ingredients' => ['nullable', 'string', 'max:5000'],
            'nutrition' => ['nullable', 'array'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'source_url' => ['nullable', 'url', 'max:2048'],
            /** The name exactly as a retailer or package prints it. */
            'title_as_listed' => ['nullable', 'string', 'max:500'],
            /** Staff only; ignored for everyone else. */
            'audit_status' => ['sometimes', Rule::in(AuditStatus::values())],
            'audit_notes' => ['nullable', 'string', 'max:5000'],
            /** Replaces the product's facet tags (`group:slug`). */
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
            /** When the name, ingredients or nutrition change: `correction` fixes the recipe on file, `new_version` keeps the old one as history. */
            'formula_change' => ['sometimes', Rule::in(['correction', 'new_version'])],
            'version_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
