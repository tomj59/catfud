<?php

namespace Tests\Feature;

use App\Enums\AuditStatus;
use App\Models\Product;
use App\Models\User;
use App\Support\ProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CatalogueAuditTest extends TestCase
{
    use RefreshDatabase;

    private function seedRow(array $over = []): array
    {
        return ['import_key' => 'catfood.xlsx|tiki cat|after dark||beef & beef liver|pate', 'brand' => 'Tiki Cat',
            'name' => 'Beef & Beef Liver', 'line' => 'After Dark', 'texture' => 'Pate', 'form' => 'wet', ...$over];
    }

    private function seeded(array $over = []): Product
    {
        app(ProductImporter::class)->import([$this->seedRow($over)], 'catfood.xlsx');

        return Product::firstOrFail();
    }

    public function test_rows_without_a_barcode_import_by_import_key_and_stay_idempotent(): void
    {
        $importer = app(ProductImporter::class);
        $r1 = $importer->import([$this->seedRow()], 'catfood.xlsx');
        $r2 = $importer->import([$this->seedRow(['texture' => 'Paté'])], 'catfood.xlsx');

        $this->assertSame(1, $r1['created']);
        $this->assertSame(1, $r2['updated']);
        $this->assertDatabaseCount('products', 1);
        $p = Product::first();
        $this->assertNull($p->gtin);
        $this->assertFalse($p->has_barcode);
        $this->assertSame('Paté', $p->texture);
        $this->assertSame(AuditStatus::Unreviewed, $p->audit_status);
    }

    public function test_a_row_with_neither_barcode_nor_key_is_an_error_and_a_bad_barcode_is_still_rejected(): void
    {
        $r = app(ProductImporter::class)->import([
            ['brand' => 'A', 'name' => 'B'],
            ['brand' => 'A', 'name' => 'C', 'gtin' => '123'],
        ], 'x');

        $this->assertSame(0, $r['created']);
        $this->assertCount(2, $r['errors']);
    }

    public function test_reimport_skips_products_a_person_has_wired_up_or_reviewed(): void
    {
        $user = User::factory()->create();
        $p = $this->seeded();
        $p->update(['gtin' => '0036000291452', 'last_edited_by' => $user->id]);

        $r = app(ProductImporter::class)->import([$this->seedRow(['name' => 'OVERWRITTEN'])], 'catfood.xlsx');

        $this->assertSame(1, $r['skipped']);
        $this->assertSame('Beef & Beef Liver', $p->fresh()->name);
        $this->assertSame('0036000291452', $p->fresh()->gtin);
    }

    public function test_attach_barcode_wires_the_scanned_code_to_a_seeded_product(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $p = $this->seeded();

        $this->putJson("/api/products/{$p->id}/barcode", ['gtin' => '036000291452'])
            ->assertOk()->assertJsonPath('product.gtin', '0036000291452')->assertJsonPath('product.has_barcode', true);

        $this->assertSame($user->id, $p->fresh()->last_edited_by);
        $this->getJson('/api/products/lookup/036000291452')->assertOk()->assertJsonPath('product.id', $p->id);
    }

    public function test_attach_barcode_rejects_bad_codes_and_conflicts(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $a = $this->seeded();
        $b = Product::create(['gtin' => '0036000291452', 'brand' => 'Acme', 'name' => 'Tuna', 'species' => 'cat', 'kind' => 'food', 'source' => 't']);

        $this->putJson("/api/products/{$a->id}/barcode", ['gtin' => '036000291453'])->assertStatus(422);
        $this->putJson("/api/products/{$a->id}/barcode", ['gtin' => '036000291452'])
            ->assertStatus(409)->assertJsonPath('product.id', $b->id); // barcode belongs to another product

        $this->putJson("/api/products/{$b->id}/barcode", ['gtin' => '4006381333931'])
            ->assertStatus(409); // already has a (different) barcode
        $this->assertNull($a->fresh()->gtin);
    }

    public function test_index_filters_by_missing_barcode_audit_status_and_multiword_search(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $a = $this->seeded();
        Product::create(['gtin' => '0036000291452', 'brand' => 'Acme', 'name' => 'Tuna', 'species' => 'cat', 'kind' => 'food', 'source' => 't']);

        $this->getJson('/api/products?barcode=missing')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $this->getJson('/api/products?barcode=present')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/products?q=tiki+beef')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/products?q=tiki+tuna')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/products?q=after+dark')->assertOk()->assertJsonCount(1, 'data'); // matches the product line
        $this->getJson('/api/products?audit=reviewed')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/products?barcode=bogus')->assertStatus(422);
    }

    public function test_update_records_the_editor_and_marking_reviewed_stamps_verification(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $p = $this->seeded();

        $this->patchJson("/api/products/{$p->id}", ['name' => 'Beef & Liver', 'audit_status' => 'reviewed', 'audit_notes' => 'checked can'])
            ->assertOk()->assertJsonPath('product.name', 'Beef & Liver')->assertJsonPath('product.audit_status', 'reviewed');

        $fresh = $p->fresh();
        $this->assertSame($user->id, $fresh->last_edited_by);
        $this->assertNotNull($fresh->last_verified_at);
        $this->patchJson("/api/products/{$p->id}", ['audit_status' => 'nonsense'])->assertStatus(422);
        $this->patchJson("/api/products/{$p->id}", ['gtin' => '4006381333931'])->assertOk();
        $this->assertNull($p->fresh()->gtin); // barcode is not editable through the general update
    }

    public function test_audit_summary_counts_progress(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->seeded();
        Product::create(['gtin' => '0036000291452', 'brand' => 'Acme', 'name' => 'Tuna', 'species' => 'cat', 'kind' => 'food', 'source' => 't', 'audit_status' => 'reviewed']);

        $this->getJson('/api/products/audit-summary')->assertOk()->assertExactJson([
            'total' => 2, 'without_barcode' => 1, 'without_image' => 2, 'unreviewed' => 1, 'reviewed' => 1, 'needs_changes' => 0,
        ]);
    }

    public function test_the_spreadsheet_seed_file_imports_cleanly_without_barcodes(): void
    {
        $path = database_path('seeds/catfood_seed.json');
        $r = app(ProductImporter::class)->importFile($path);

        $this->assertSame([], $r['errors']);
        $this->assertGreaterThan(250, $r['created']);
        $this->assertSame($r['created'], Product::whereNull('gtin')->count());
        $this->assertSame(0, Product::where('audit_status', '!=', 'unreviewed')->count());

        $again = app(ProductImporter::class)->importFile($path);
        $this->assertSame(0, $again['created']); // idempotent
        $this->assertDatabaseCount('products', $r['created']);

        $tiki = Product::where('brand', 'Tiki Cat')->where('name', 'Beef & Beef Liver')->first();
        $this->assertSame('After Dark Line', $tiki->line);
        $this->assertArrayHasKey('protein_pct', $tiki->nutrition);
        $this->assertSame('Tiki Cat', $tiki->meta['sheet']);
    }
}
