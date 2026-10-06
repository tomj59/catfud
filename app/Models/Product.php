<?php

namespace App\Models;

use App\Enums\AuditStatus;
use App\Enums\ProductKind;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToRegion;
use App\Models\Scopes\VisibleScope;
use App\Support\Gtin;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use Auditable, BelongsToRegion;

    protected $with = ['tags', 'barcodes'];

    protected $fillable = [
        'gtin', 'brand', 'name', 'species', 'kind', 'form', 'description', 'ingredients',
        'nutrition', 'image_url', 'source', 'last_verified_at', 'created_by',
        'import_key', 'line', 'texture', 'source_url', 'meta', 'audit_status', 'audit_notes', 'last_edited_by',
        'region', 'brand_node_id', 'path_text', 'search_text', 'title_as_listed', 'requested_path', 'moderation_status',
    ];

    protected $hidden = ['search_text', 'created_by', 'last_edited_by', 'reviewed_by', 'merged_into_id', 'gtin_scope'];

    protected $appends = ['upc_a', 'has_barcode', 'placed'];

    /** The fields that make up "the recipe on file". A change to any of them is a correction or a new version. */
    public const FORMULA_FIELDS = ['name', 'title_as_listed', 'ingredients', 'nutrition'];

    /** Set before saving: 'new_version' keeps the old recipe as history; anything else corrects the current version in place. */
    public ?string $formulaChange = null;

    public ?int $formulaUser = null;

    public ?string $formulaNote = null;

    protected static function booted(): void
    {
        static::addGlobalScope(new VisibleScope);

        // The public catalogue shares scope 0; a contributor's private copy is scoped to them (see the migration).
        static::saving(function (self $p) {
            $p->gtin_scope = $p->moderation_status === 'approved' ? 0 : (int) $p->created_by;
        });

        static::created(function (self $p) {
            $p->versions()->create($p->snapshot() + ['version' => 1, 'is_current' => true, 'source' => $p->source, 'observed_at' => now()]);
        });

        static::saved(function (self $p) {
            if (! $p->wasRecentlyCreated && $p->wasChanged(self::FORMULA_FIELDS)) {
                $p->recordFormula();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => ProductKind::class,
            'audit_status' => AuditStatus::class,
            'nutrition' => 'array',
            'meta' => 'array',
            'last_verified_at' => 'datetime',
            'formula_changed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** The 12-digit UPC-A form of the stored GTIN-13, when it has one. */
    public function getUpcAAttribute(): ?string
    {
        return $this->gtin ? Gtin::toUpcA($this->gtin) : null;
    }

    /** Find a product by any scanned or typed code (UPC-A, EAN-13, EAN-8, GTIN-14 with leading 0). */
    public static function findByCode(string $code): ?self
    {
        $gtin = Gtin::normalize($code);
        if (! $gtin) {
            return null;
        }

        // The public product wins over anyone's private copy of the same barcode.
        return static::where('gtin', $gtin)->orderByRaw("moderation_status = 'approved' desc")->first()
            ?? static::whereHas('barcodes', fn ($q) => $q->where('gtin', $gtin))->first();
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(BrandNode::class, 'brand_node_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('group')->orderBy('sort');
    }

    public function barcodes()
    {
        return $this->hasMany(ProductBarcode::class);
    }

    /** False for a contributed product still waiting for its ladder to exist; it keeps the ladder the person typed. */
    public function getPlacedAttribute(): bool
    {
        return $this->brand_node_id !== null;
    }

    public function isPublic(): bool
    {
        return $this->moderation_status === 'approved';
    }

    public function isOwnedBy(?User $user): bool
    {
        return $user !== null && $this->created_by !== null && (int) $this->created_by === (int) $user->getKey();
    }

    /** Has someone scanned a real package and wired up a barcode yet? */
    public function getHasBarcodeAttribute(): bool
    {
        return $this->gtin !== null;
    }

    /** Seeded rows that a person has not touched yet; only these may be refreshed by a re-import. */
    public function isUntouched(): bool
    {
        return $this->gtin === null && $this->audit_status === AuditStatus::Unreviewed && $this->last_edited_by === null;
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProductVersion::class)->orderByDesc('version');
    }

    /** @return array{name:string, title_as_listed:?string, ingredients:?string, nutrition:?array} */
    public function snapshot(): array
    {
        return ['name' => $this->name, 'title_as_listed' => $this->title_as_listed, 'ingredients' => $this->ingredients, 'nutrition' => $this->nutrition];
    }

    /**
     * Called after a save that touched the recipe. A correction rewrites the current version (fixing a typo is not a new
     * recipe); a 'new_version' closes it and opens the next one, so the earlier recipe stays on record.
     */
    private function recordFormula(): void
    {
        $current = ProductVersion::where('product_id', $this->id)->where('is_current', true)->first();

        if ($current && $this->formulaChange === 'new_version') {
            $next = $current->version + 1;
            $current->update(['is_current' => false]);
            ProductVersion::create($this->snapshot() + ['product_id' => $this->id, 'version' => $next, 'is_current' => true,
                'source' => 'edit', 'observed_by' => $this->formulaUser, 'observed_at' => now(), 'note' => $this->formulaNote]);
            $this->forceFill(['formula_version' => $next, 'formula_changed_at' => now()])->saveQuietly();
        } elseif ($current) {
            $current->update($this->snapshot());
        } else {
            ProductVersion::create($this->snapshot() + ['product_id' => $this->id, 'version' => 1, 'is_current' => true, 'source' => 'edit', 'observed_at' => now()]);
        }

        $this->formulaChange = $this->formulaNote = null;
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }
}
