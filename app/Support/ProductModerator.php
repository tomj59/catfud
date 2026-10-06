<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\BrandNode;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Scopes\VisibleScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The moderator's moves on a contributed product: approve it into the public catalogue, send it back, decline it,
 * place it on the ladder, or fold it into an existing product when it is a duplicate. Every move raises a RuntimeException
 * with a message fit to show a person when it cannot be done.
 */
class ProductModerator
{
    public function __construct(private BrandTreeMapper $mapper) {}

    public function approve(Product $product, User $by, ?string $note = null): Product
    {
        if (! $product->placed) {
            throw new RuntimeException('Place this product on the brand ladder first. An unplaced product cannot be made public.');
        }
        if ($product->gtin && ($twin = $this->publicTwin($product))) {
            throw new RuntimeException("Product #{$twin->id} ({$twin->name}) already holds that barcode in the public catalogue. Merge this one into it instead.");
        }

        return $this->mark($product, 'approved', $by, $note);
    }

    public function needsChanges(Product $product, User $by, string $note): Product
    {
        return $this->mark($product, 'needs_changes', $by, $note);
    }

    public function reject(Product $product, User $by, string $note): Product
    {
        return $this->mark($product, 'rejected', $by, $note);
    }

    /** Put a product on a node that already exists. A moderator-chosen placement is locked against re-imports. */
    public function place(Product $product, BrandNode $node): Product
    {
        $this->mapper->place($product, $node);
        $product->forceFill(['requested_path' => null, 'meta' => [...($product->meta ?? []), 'path_locked' => true]])->save();

        return $product->fresh();
    }

    /**
     * Fold a duplicate into the product that should survive. Pantry stock, ratings, meal history and advisory matches
     * follow it; where the person already has both, the survivor's row wins. The duplicate's barcode becomes a pack
     * barcode on the survivor.
     */
    public function mergeInto(Product $duplicate, Product $survivor, User $by, ?string $note = null): Product
    {
        if ($duplicate->id === $survivor->id) {
            throw new RuntimeException('Pick a different product to merge into.');
        }
        if (! $survivor->isPublic()) {
            throw new RuntimeException('Products can only be merged into an approved product.');
        }

        return DB::transaction(function () use ($duplicate, $survivor, $by, $note) {
            $this->repoint('inventory_items', 'user_id', $duplicate, $survivor);
            $this->repoint('ratings', 'pet_id', $duplicate, $survivor);
            $this->repoint('advisory_matches', 'advisory_id', $duplicate, $survivor);
            DB::table('meal_offers')->where('product_id', $duplicate->id)->update(['product_id' => $survivor->id]);

            $gtin = $duplicate->gtin;
            $packs = ProductBarcode::where('product_id', $duplicate->id)->get();
            $duplicate->forceFill(['gtin' => null])->saveQuietly();
            ProductBarcode::where('product_id', $duplicate->id)->update(['product_id' => $survivor->id]);
            if ($gtin) {
                if ($survivor->gtin === null) {
                    $survivor->forceFill(['gtin' => $gtin])->save();
                } elseif ($survivor->gtin !== $gtin && ! ProductBarcode::where('gtin', $gtin)->exists()) {
                    ProductBarcode::create(['product_id' => $survivor->id, 'gtin' => $gtin, 'pack_label' => 'merged', 'added_by' => $by->id]);
                }
            }

            $duplicate->forceFill(['moderation_status' => 'merged', 'merged_into_id' => $survivor->id,
                'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
            AuditLog::record('merged', $duplicate, ['merged_into_id' => [null, $survivor->id], 'moved_barcodes' => $packs->count() + ($gtin ? 1 : 0)], $note);

            return $survivor->fresh();
        });
    }

    private function mark(Product $product, string $status, User $by, ?string $note): Product
    {
        if ($product->moderation_status === 'merged') {
            throw new RuntimeException('This product was merged into another one and cannot be reviewed.');
        }
        $product->forceFill(['moderation_status' => $status, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_note' => $note])->save();

        return $product->fresh();
    }

    private function publicTwin(Product $product): ?Product
    {
        return Product::withoutGlobalScope(VisibleScope::class)->where('gtin', $product->gtin)
            ->where('moderation_status', 'approved')->where('id', '!=', $product->id)->first();
    }

    /** Move rows that point at $from to $to, dropping the ones that would collide on (owner, product). */
    private function repoint(string $table, string $ownerColumn, Product $from, Product $to): void
    {
        $has = DB::table($table)->where('product_id', $to->id)->pluck($ownerColumn);
        DB::table($table)->where('product_id', $from->id)->whereIn($ownerColumn, $has)->delete();
        DB::table($table)->where('product_id', $from->id)->update(['product_id' => $to->id]);
    }
}
