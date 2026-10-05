<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditStatus;
use App\Enums\ProductKind;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\AdvisoryPresenter;
use App\Support\Gtin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /** Search the shared catalogue: ?q= matches brand or name; ?species= and ?kind= filter. */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'kind' => ['nullable', Rule::in(ProductKind::values())],
            'barcode' => ['nullable', Rule::in(['missing', 'present'])],
            'audit' => ['nullable', Rule::in(AuditStatus::values())],
            'form' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = Product::query()->orderBy('brand')->orderBy('line')->orderBy('name');

        // Every word must match somewhere in brand, line, name or variety, so "tiki beef" finds Tiki Cat > Beef & Liver.
        foreach (preg_split('/\s+/', trim((string) $request->query('q')), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $word).'%';
            $query->where(fn ($w) => $w->where('brand', 'like', $like)->orWhere('name', 'like', $like)->orWhere('line', 'like', $like));
        }
        if ($barcode = $request->query('barcode')) {
            $barcode === 'missing' ? $query->whereNull('gtin') : $query->whereNotNull('gtin');
        }
        if ($audit = $request->query('audit')) {
            $query->where('audit_status', $audit);
        }
        if ($form = $request->query('form')) {
            $query->whereRaw('lower(form) = ?', [strtolower($form)]); // wet / dry / freeze-dried; omit for everything
        }
        if ($species = $request->query('species')) {
            $query->where('species', $species);
        }
        if ($kind = $request->query('kind')) {
            $query->where('kind', $kind);
        }

        return response()->json($query->paginate((int) $request->query('per_page', 25)));
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        return response()->json($this->withAdvisories($request, $product));
    }

    /**
     * Look up a product by whatever the scanner (or the user's fingers) produced.
     * Found: 200 with the product. Valid code but unknown product: 404 with `gtin`, so the app can offer "add it".
     * Not a valid barcode: 422.
     */
    public function lookup(Request $request, string $code): JsonResponse
    {
        $gtin = Gtin::normalize($code);

        if ($gtin === null) {
            return response()->json([
                'message' => 'That is not a valid UPC/EAN (wrong length or check digit).',
                'errors' => ['code' => ['Invalid barcode.']],
            ], 422);
        }

        $product = Product::where('gtin', $gtin)->first();

        if (! $product) {
            return response()->json([
                'message' => 'No product with that barcode yet.',
                'gtin' => $gtin,
                'upc_a' => Gtin::toUpcA($gtin),
            ], 404);
        }

        return response()->json($this->withAdvisories($request, $product));
    }

    /** The product plus any Public Advisories that may relate to it, each attributed to its source. @return array<string,mixed> */
    private function withAdvisories(Request $request, Product $product): array
    {
        return [
            'product' => $product,
            'advisories' => AdvisoryPresenter::forProduct($product->id, $request->user()),
            'advisory_disclaimer' => config('catfud.advisory_disclaimer'),
        ];
    }

    /** Add a product that the scan lookup could not find. The creator is recorded as provenance. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gtin' => ['required', 'string'],
            'brand' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'species' => ['nullable', 'string', 'max:50'],
            'kind' => ['nullable', Rule::in(ProductKind::values())],
            'form' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'ingredients' => ['nullable', 'string', 'max:5000'],
            'nutrition' => ['nullable', 'array'],
            'image_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $gtin = Gtin::normalize($data['gtin']);
        if ($gtin === null) {
            return response()->json([
                'message' => 'That is not a valid UPC/EAN (wrong length or check digit).',
                'errors' => ['gtin' => ['Invalid barcode.']],
            ], 422);
        }

        if ($existing = Product::where('gtin', $gtin)->first()) {
            return response()->json(['message' => 'A product with that barcode already exists.', 'product' => $existing], 409);
        }

        $product = Product::create([
            ...$data,
            'gtin' => $gtin,
            'species' => $data['species'] ?? 'cat',
            'kind' => $data['kind'] ?? 'food',
            'source' => 'user:'.$request->user()->id,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['product' => $product], 201);
    }

    /** Progress for the seed -> wire-up -> audit pass. */
    public function auditSummary(): JsonResponse
    {
        return response()->json([
            'total' => Product::count(),
            'without_barcode' => Product::whereNull('gtin')->count(),
            'without_image' => Product::whereNull('image_url')->count(),
            'unreviewed' => Product::where('audit_status', AuditStatus::Unreviewed->value)->count(),
            'reviewed' => Product::where('audit_status', AuditStatus::Reviewed->value)->count(),
            'needs_changes' => Product::where('audit_status', AuditStatus::NeedsChanges->value)->count(),
        ]);
    }

    /**
     * Wire a scanned barcode to an existing (seeded) product. 409 if the product already has a barcode, or if the
     * barcode already belongs to a different product (the response names it, so the app can say so).
     */
    public function attachBarcode(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate(['gtin' => ['required', 'string']]);

        $gtin = Gtin::normalize($data['gtin']);
        if ($gtin === null) {
            return response()->json([
                'message' => 'That is not a valid UPC/EAN (wrong length or check digit).',
                'errors' => ['gtin' => ['Invalid barcode.']],
            ], 422);
        }

        if ($product->gtin !== null) {
            return response()->json([
                'message' => $product->gtin === $gtin ? 'That barcode is already on this product.' : 'This product already has a different barcode.',
                'product' => $product,
            ], 409);
        }

        if ($other = Product::where('gtin', $gtin)->first()) {
            return response()->json(['message' => 'That barcode already belongs to another product.', 'product' => $other], 409);
        }

        $product->update(['gtin' => $gtin, 'last_edited_by' => $request->user()->id]);

        return response()->json(['product' => $product->fresh()]);
    }

    /**
     * Correct a catalogue record during the audit pass. The barcode is changed only through attachBarcode.
     * Pilot note: any signed-in user can edit the shared catalogue; real roles are a later question.
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'brand' => ['sometimes', 'required', 'string', 'max:255'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'line' => ['nullable', 'string', 'max:255'],
            'texture' => ['nullable', 'string', 'max:255'],
            'form' => ['nullable', 'string', 'max:100'],
            'kind' => ['sometimes', Rule::in(ProductKind::values())],
            'description' => ['nullable', 'string', 'max:5000'],
            'ingredients' => ['nullable', 'string', 'max:5000'],
            'nutrition' => ['nullable', 'array'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'source_url' => ['nullable', 'url', 'max:2048'],
            'audit_status' => ['sometimes', Rule::in(AuditStatus::values())],
            'audit_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $product->fill($data);
        $product->last_edited_by = $request->user()->id;
        if (($data['audit_status'] ?? null) === AuditStatus::Reviewed->value) {
            $product->last_verified_at = now();
        }
        $product->save();

        return response()->json(['product' => $product->fresh()]);
    }
}
