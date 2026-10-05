<?php

namespace Tests\Feature;

use App\Models\BrandNode;
use App\Models\Product;
use App\Models\User;
use App\Support\BrandTreeMapper;
use App\Support\ProductImporter;
use App\Support\Region;
use App\Support\TextureParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrandTreeTest extends TestCase
{
    use RefreshDatabase;

    private function row(array $over = []): array
    {
        return ['import_key' => 'k'.uniqid(), 'brand' => 'Tiki Cat', 'name' => 'Beef', 'line' => 'After Dark Line', 'texture' => 'Pate', 'form' => 'wet', ...$over];
    }

    private function import(array $rows): void
    {
        app(ProductImporter::class)->import($rows, 'test');
    }

    public function test_texture_and_medium_are_parsed_into_separate_tags(): void
    {
        $this->assertSame(['texture:pate'], TextureParser::tagKeys('Paté'));
        $this->assertEqualsCanonicalizing(['texture:shreds', 'texture:pate'], TextureParser::tagKeys('Shreds & Pate'));
        $this->assertEqualsCanonicalizing(['texture:cuts', 'medium:gravy'], TextureParser::tagKeys('Cuts in Gravy'));
        $this->assertEqualsCanonicalizing(['texture:minced', 'medium:broth'], TextureParser::tagKeys('Mince in Broth'));
        $this->assertSame(['medium:jelly'], TextureParser::tagKeys('(Originals) (Gelee)'));
        $this->assertSame([], TextureParser::tagKeys('Pellets'));
        $this->assertSame([], TextureParser::tagKeys(null));
    }

    public function test_import_builds_a_brand_line_ladder_and_keeps_display_caches(): void
    {
        $this->import([$this->row()]);
        $p = Product::first();

        $this->assertSame('Tiki Cat › After Dark', $p->path_text);
        $this->assertSame('Tiki Cat', $p->brand);
        $this->assertSame('After Dark', $p->line);
        $this->assertSame(2, $p->node->depth);
        $this->assertSame(['texture:pate'], $p->tags->map(fn ($t) => "{$t->group}:{$t->slug}")->all());
    }

    public function test_rules_put_manufacturers_above_brands_and_varieties_below_them(): void
    {
        $this->import([
            $this->row(['brand' => 'Fancy Feast', 'line' => 'Creamy Delights (with a touch of real milk)', 'name' => 'Chicken']),
            $this->row(['brand' => 'Purina', 'line' => 'Purina ONE', 'name' => 'Chicken Recipe', 'meta' => ['variety' => 'Grain Free']]),
            $this->row(['brand' => "Hill's", 'line' => 'Prescription Line', 'name' => 'Weight R/D', 'meta' => ['variety' => 'Weight']]),
            $this->row(['brand' => "Hill's", 'line' => 'Adult Line', 'name' => 'Savory Chicken', 'meta' => ['variety' => 'Indoor']]),
        ]);

        $paths = Product::orderBy('id')->pluck('path_text')->all();
        $this->assertSame([
            'Purina › Fancy Feast › Creamy Delights',
            'Purina › Purina ONE › Grain Free',
            "Hill's › Prescription Diet › Weight",
            "Hill's › Science Diet › Adult › Indoor",
        ], $paths);

        $one = Product::where('name', 'Chicken Recipe')->first();
        $this->assertSame('Purina ONE', $one->brand);
        $this->assertSame('Grain Free', $one->line);

        $rx = Product::where('name', 'Weight R/D')->first();
        $this->assertTrue($rx->tags->contains(fn ($t) => $t->group === 'diet' && $t->slug === 'clinical'));
        $this->assertFalse(Product::where('name', 'Savory Chicken')->first()->tags->contains(fn ($t) => $t->group === 'diet'));
    }

    public function test_depth_is_capped_at_five(): void
    {
        $five = BrandNode::ensurePath(array_map(fn ($n) => ['name' => "L{$n}"], range(1, 5)));
        $this->assertSame(5, $five->depth);

        $this->expectException(\InvalidArgumentException::class);
        BrandNode::ensurePath(array_map(fn ($n) => ['name' => "L{$n}"], range(1, 6)));
    }

    public function test_the_tree_is_separate_per_region(): void
    {
        $this->import([$this->row(['gtin' => '036000291452'])]);
        $this->assertSame(1, Product::count());

        Region::using('CA', function () {
            $this->assertSame(0, Product::count());               // a different region is a different catalogue
            $this->assertSame(0, BrandNode::count());
            $this->import([$this->row(['name' => 'Beef (CA recipe)', 'gtin' => '036000291452'])]); // same barcode, different product
            $this->assertSame(1, Product::count());
            $this->assertSame('Beef (CA recipe)', Product::first()->name);
        });

        $this->assertSame(1, Product::count());
        $this->assertSame('Beef', Product::first()->name);
        $this->assertSame(2, Product::withoutGlobalScopes()->count());
    }

    public function test_a_signed_in_user_only_sees_their_regions_catalogue(): void
    {
        $this->import([$this->row()]);
        $ca = User::factory()->create();
        $ca->forceFill(['region' => 'CA'])->save();

        Sanctum::actingAs($ca);
        $this->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/brand-nodes')->assertOk()->assertJsonCount(0, 'nodes');

        Region::reset();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/products')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_browse_the_tree_level_by_level_with_counts_that_honour_filters(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->import([
            $this->row(['brand' => 'Fancy Feast', 'line' => 'Classic', 'name' => 'A']),
            $this->row(['brand' => 'Fancy Feast', 'line' => 'Classic', 'name' => 'B', 'gtin' => '036000291452']),
            $this->row(['brand' => 'Fancy Feast', 'line' => 'Medleys', 'name' => 'C']),
            $this->row(['brand' => 'Purina Cat Chow', 'line' => null, 'name' => 'D', 'form' => 'dry']),
        ]);

        $top = $this->getJson('/api/brand-nodes')->assertOk();
        $this->assertSame(['Purina'], collect($top->json('nodes'))->pluck('name')->all());
        $this->assertSame(4, $top->json('nodes.0.product_count'));

        $purina = $top->json('nodes.0.id');
        $brands = $this->getJson("/api/brand-nodes?parent={$purina}")->assertOk();
        $this->assertSame(['Cat Chow', 'Fancy Feast'], collect($brands->json('nodes'))->pluck('name')->all());

        $ff = collect($brands->json('nodes'))->firstWhere('name', 'Fancy Feast');
        $this->assertTrue($ff['has_children']);
        $lines = $this->getJson("/api/brand-nodes?parent={$ff['id']}&barcode=missing")->assertOk();
        $this->assertSame(['Classic' => 1, 'Medleys' => 1], collect($lines->json('nodes'))->pluck('product_count', 'name')->all());
        $this->assertSame(['Purina', 'Fancy Feast'], collect($lines->json('parent.path'))->pluck('name')->all());

        $wet = $this->getJson('/api/brand-nodes?form=dry')->assertOk();
        $this->assertSame(1, $wet->json('nodes.0.product_count'));
    }

    public function test_search_matches_the_path_and_products_in_a_branch(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->import([
            $this->row(['brand' => 'Fancy Feast', 'line' => 'Classic', 'name' => 'Chicken Feast']),
            $this->row(['brand' => 'Purina', 'line' => 'Purina Pro Plan', 'name' => 'Salmon', 'texture' => null]),
        ]);

        $this->getJson('/api/products?q=purina+classic')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Chicken Feast');
        $this->getJson('/api/products?q=purina+pro+plan')->assertOk()->assertJsonCount(1, 'data'); // alias "Purina Pro Plan"

        $purina = BrandNode::where('name', 'Purina')->whereNull('parent_id')->first();
        $this->getJson("/api/products?node={$purina->id}")->assertOk()->assertJsonCount(2, 'data');
        $ff = BrandNode::where('name', 'Fancy Feast')->first();
        $this->getJson("/api/products?node={$ff->id}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_texture_filters_include_and_exclude(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->import([
            $this->row(['name' => 'Smooth', 'texture' => 'Pate']),
            $this->row(['name' => 'Bits', 'texture' => 'Chunks in Gravy']),
            $this->row(['name' => 'Plain', 'texture' => null]),
        ]);

        $names = fn (string $qs) => collect($this->getJson("/api/products?{$qs}")->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Smooth'], $names('tag[]=texture:pate'));
        $this->assertSame(['Bits', 'Smooth'], $names('tag[]=texture:pate&tag[]=texture:chunks'));   // alternatives within a group
        $this->assertSame(['Bits'], $names('tag[]=medium:gravy'));
        $this->assertSame(['Plain', 'Smooth'], $names('exclude_tag[]=texture:chunks'));
        $this->assertSame(['Plain'], $names('exclude_tag[]=texture:chunks&exclude_tag[]=texture:pate'));
    }

    public function test_an_extra_pack_barcode_can_be_added_and_is_found_by_scanning(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $p = Product::create(['gtin' => '0036000291452', 'brand' => 'A', 'name' => 'Can', 'species' => 'cat', 'kind' => 'food', 'source' => 't']);

        $this->putJson("/api/products/{$p->id}/barcode", ['gtin' => '4006381333931', 'pack_label' => '24-can case'])
            ->assertOk()->assertJsonPath('product.gtin', '0036000291452')->assertJsonPath('product.barcodes.0.pack_label', '24-can case');

        $this->getJson('/api/products/lookup/4006381333931')->assertOk()->assertJsonPath('product.id', $p->id);
        $this->putJson("/api/products/{$p->id}/barcode", ['gtin' => '4006381333931'])->assertStatus(409);
    }

    public function test_audit_edits_can_move_a_product_in_the_tree_and_set_tags(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->import([$this->row(['brand' => 'Pro Plan', 'line' => 'Complete Essentials'])]);
        $p = Product::first();

        $this->patchJson("/api/products/{$p->id}", ['path' => 'Purina > Pro Plan > Complete Essentials', 'tags' => ['diet:clinical', 'texture:mousse']])
            ->assertOk()->assertJsonPath('product.path_text', 'Purina › Pro Plan › Complete Essentials')->assertJsonPath('product.brand', 'Pro Plan');

        $this->assertEqualsCanonicalizing(['diet:clinical', 'texture:mousse'], $p->fresh()->tags->map(fn ($t) => "{$t->group}:{$t->slug}")->all());
        $this->patchJson("/api/products/{$p->id}", ['path' => 'A > B > C > D > E > F'])->assertStatus(422);

        // The person's choice survives a rebuild.
        app(BrandTreeMapper::class)->apply($p->fresh());
        $this->assertSame('Purina › Pro Plan › Complete Essentials', $p->fresh()->path_text);
    }

    public function test_build_tree_command_places_existing_products_without_touching_anything_else(): void
    {
        $p = Product::create(['gtin' => '0036000291452', 'brand' => 'Fancy Feast', 'line' => 'Classic', 'texture' => 'Pate', 'name' => 'Chicken', 'species' => 'cat', 'kind' => 'food', 'source' => 't', 'audit_status' => 'reviewed']);

        $this->artisan('catalogue:build-tree')->assertSuccessful();

        $p = $p->fresh();
        $this->assertSame('Purina › Fancy Feast › Classic', $p->path_text);
        $this->assertSame('reviewed', $p->audit_status->value);
        $this->assertSame('0036000291452', $p->gtin);
        $this->assertSame('Chicken', $p->name);
    }

    public function test_an_advisory_naming_a_manufacturer_matches_every_brand_beneath_it(): void
    {
        $this->import([
            $this->row(['brand' => 'Fancy Feast', 'line' => 'Classic', 'name' => 'A']),
            $this->row(['brand' => 'Purina Cat Chow', 'line' => null, 'name' => 'B']),
            $this->row(['brand' => 'Tiki Cat', 'name' => 'C']),
        ]);
        $json = json_encode(['advisories' => [[
            'source_name' => 'Test', 'source_url' => 'https://example.test/a', 'title' => 'T', 'summary' => 'S', 'source_text' => 'S',
            'published_at' => '2026-01-01', 'matches' => [['brand' => 'Purina']],
        ]]]);
        $path = sys_get_temp_dir().'/adv_'.uniqid().'.json';
        file_put_contents($path, $json);

        $r = app(\App\Support\AdvisoryImporter::class)->importFile($path);
        $this->assertSame(2, \App\Models\AdvisoryMatch::count(), json_encode($r));
    }
}
