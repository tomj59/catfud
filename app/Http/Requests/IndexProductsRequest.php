<?php

namespace App\Http\Requests;

use App\Enums\AuditStatus;
use App\Enums\ProductKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Every word must match brand, name, line or the brand path and its aliases. */
            'q' => ['nullable', 'string', 'max:200'],
            'kind' => ['nullable', Rule::in(ProductKind::values())],
            /** Wired up (`present`) or not yet (`missing`). */
            'barcode' => ['nullable', Rule::in(['missing', 'present'])],
            'audit' => ['nullable', Rule::in(AuditStatus::values())],
            /** wet, dry or freeze-dried; omit for everything. */
            'form' => ['nullable', 'string', 'max:100'],
            'species' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            /** Limit to a brand-tree node and everything beneath it. */
            'node' => ['nullable', 'integer'],
            /** Limit to a brand-ladder path written as names, e.g. `Purina > Pro Plan`, and everything beneath it. An unknown path matches nothing. */
            'path' => ['nullable', 'string', 'max:300'],
            /** Include these facet tags (`group:slug`). Different groups AND together; tags in one group are alternatives. */
            'tag' => ['nullable', 'array', 'max:20'],
            'tag.*' => ['string', 'max:60'],
            /** Exclude products carrying these facet tags. */
            'exclude_tag' => ['nullable', 'array', 'max:20'],
            'exclude_tag.*' => ['string', 'max:60'],
        ];
    }
}
