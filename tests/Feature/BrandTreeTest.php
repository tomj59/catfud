<?php

namespace Tests\Feature;

use App\Models\BrandNode;
use App\Models\Product;
use App\Models\User;
use App\Support\BrandTreeMapper;
use App\Support\LifeStageParser;
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
            "Hill's › Science Diet › Indoor",      // "Adult" is a life stage, so it rides as a tag, not a rung
        ], $paths);
        $sd = Product::where('name', 'Savory Chicken')->first();
        $this->assertTrue($sd->tags->contains(fn ($t) => $t->group === 'life_stage' && $t->slug === 'adult'));

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
        $ca = User::factory()->admin()->create();
        $ca->forceFill(['region' => 'CA'])->save();

        Sanctum::actingAs($ca);
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/brand-nodes')->assertOk()->assertJsonCount(0, 'nodes');

        Region::reset();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/products')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_browse_the_tree_level_by_level_with_counts_that_honour_filters(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->import([
            $this->row(['brand' => 'Fancy Feast', 'line' => 'Classic', 'name' => 'A']),
            $this->row(['brand' => 'Fancy Feast', 'line' => 'Classic', 'name' => 'B', 'gtin' => '036000291452']),
            $this->row(['brand' => 'Fancy Feast', 'line' => 'Medleys', 'name' => 'C']),
            $this->row(['brand' => 'Purina Cat Chow', 'line' => null, 'name' => 'D', 'form' => 'dry']),
        ]);

        $top = $this->getJson('/api/v1/brand-nodes')->assertOk();
        $this->assertSame(['Purina'], collect($top->json('nodes'))->pluck('name')->all());
        $this->assertSame(4, $top->json('nodes.0.product_count'));

        $purina = $top->json('nodes.0.id');
        $brands = $this->getJson("/api/v1/brand-nodes?parent={$purina}")->assertOk();
        $this->assertSame(['Cat Chow', 'Fancy Feast'], collect($brands->json('nodes'))->pluck('name')->all());

        $ff = collect($brands->json('nodes'))->firstWhere('name', 'Fancy Feast');
        $this->assertTrue($ff['has_children']);
        $lines = $this->getJson("/api/v1/brand-nodes?parent={$ff['id']}&barcode=missing")->assertOk();
        $this->assertSame(['Classic' => 1, 'Medleys' => 1], collect($lines->json('nodes'))->pluck('product_count', 'name')->all());
        $this->assertSame(['Purina', 'Fancy Feast'], collect($lines->json('parent.path'))->pluck('name')->all());

        $wet = $this->getJson('/api/v1/brand-nodes?form=dry')->assertOk();
        $this->assertSame(1, $wet->json('nodes.0.product_count'));
    }

    public function test_search_matches_the_path_and_products_in_a_branch(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->import([
            $this->row(['brand' => 'Fancy Feast', 'line' => 'Classic', 'name' => 'Chicken Feast']),
            $this->row(['brand' => 'Purina', 'line' => 'Purina Pro Plan', 'name' => 'Salmon', 'texture' => null]),
        ]);

        $this->getJson('/api/v1/products?q=purina+classic')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Chicken Feast');
        $this->getJson('/api/v1/products?q=purina+pro+plan')->assertOk()->assertJsonCount(1, 'data'); // alias "Purina Pro Plan"

        $purina = BrandNode::where('name', 'Purina')->whereNull('parent_id')->first();
        $this->getJson("/api/v1/products?node={$purina->id}")->assertOk()->assertJsonCount(2, 'data');
        $ff = BrandNode::where('name', 'Fancy Feast')->first();
        $this->getJson("/api/v1/products?node={$ff->id}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_texture_filters_include_and_exclude(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->import([
            $this->row(['name' => 'Smooth', 'texture' => 'Pate']),
            $this->row(['name' => 'Bits', 'texture' => 'Chunks in Gravy']),
            $this->row(['name' => 'Plain', 'texture' => null]),
        ]);

        $names = fn (string $qs) => collect($this->getJson("/api/v1/products?{$qs}")->assertOk()->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Smooth'], $names('tag[]=texture:pate'));
        $this->assertSame(['Bits', 'Smooth'], $names('tag[]=texture:pate&tag[]=texture:chunks'));   // alternatives within a group
        $this->assertSame(['Bits'], $names('tag[]=medium:gravy'));
        $this->assertSame(['Plain', 'Smooth'], $names('exclude_tag[]=texture:chunks'));
        $this->assertSame(['Plain'], $names('exclude_tag[]=texture:chunks&exclude_tag[]=texture:pate'));
    }

    public function test_an_extra_pack_barcode_can_be_added_and_is_found_by_scanning(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $p = Product::create(['gtin' => '0036000291452', 'brand' => 'A', 'name' => 'Can', 'species' => 'cat', 'kind' => 'food', 'source' => 't']);

        $this->putJson("/api/v1/products/{$p->id}/barcode", ['gtin' => '4006381333931', 'pack_label' => '24-can case'])
            ->assertOk()->assertJsonPath('product.gtin', '0036000291452')->assertJsonPath('product.barcodes.0.pack_label', '24-can case');

        $this->getJson('/api/v1/products/lookup/4006381333931')->assertOk()->assertJsonPath('product.id', $p->id);
        $this->putJson("/api/v1/products/{$p->id}/barcode", ['gtin' => '4006381333931'])->assertStatus(409);
    }

    public function test_audit_edits_can_move_a_product_in_the_tree_and_set_tags(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->import([$this->row(['brand' => 'Pro Plan', 'line' => 'Complete Essentials'])]);
        $p = Product::first();

        $this->patchJson("/api/v1/products/{$p->id}", ['path' => 'Purina > Pro Plan > Complete Essentials', 'tags' => ['diet:clinical', 'texture:mousse']])
            ->assertOk()->assertJsonPath('product.path_text', 'Purina › Pro Plan › Complete Essentials')->assertJsonPath('product.brand', 'Pro Plan');

        $this->assertEqualsCanonicalizing(['diet:clinical', 'texture:mousse'], $p->fresh()->tags->map(fn ($t) => "{$t->group}:{$t->slug}")->all());
        $this->patchJson("/api/v1/products/{$p->id}", ['path' => 'A > B > C > D > E > F'])->assertStatus(422);

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

    public function test_brand_choices_merge_existing_nodes_with_suggestions_and_stop_at_max_depth(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        BrandNode::ensurePath([['name' => 'Purina'], ['name' => 'Pro Plan'], ['name' => 'Savor']]);

        $top = $this->getJson('/api/v1/brand-choices')->assertOk()->json();
        $names = array_column($top['choices'], 'name');
        $this->assertContains('Purina', $names);
        $this->assertContains('Blue Buffalo', $names);   // suggestion, no node yet
        $this->assertSame('Purina', $names[0]);             // familiar names lead, in the suggestion file's order
        $this->assertLessThan(array_search('Nutro', $names), array_search("Hill's", $names));

        $purina = $this->getJson('/api/v1/brand-choices?path='.urlencode('Purina'))->json();
        $byName = collect($purina['choices'])->keyBy('name');
        $this->assertTrue($byName['Pro Plan']['known']);      // already in the tree
        $this->assertFalse($byName['Gourmet']['known']);      // suggested only
        $this->assertTrue($purina['can_go_deeper']);

        $sub = $this->getJson('/api/v1/brand-choices?path='.urlencode('Purina > Pro Plan'))->json();
        $subNames = array_column($sub['choices'], 'name');
        $this->assertSame('Complete Essentials', $subNames[0]);   // curated lines lead, in file order
        $this->assertContains('Savor', $subNames);

        $deep = $this->getJson('/api/v1/brand-choices?path='.urlencode('A > B > C > D > E'))->json();
        $this->assertFalse($deep['can_go_deeper']);
        $this->assertSame([], $deep['choices']);
    }

    public function test_brand_choices_include_a_logo_only_when_the_file_exists(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $by = collect($this->getJson('/api/v1/brand-choices')->json('choices'))->keyBy('name');
        $this->assertSame('/images/brands/hills.png', $by["Hill's"]['logo']);
        $this->assertNull($by['Tiki Cat']['logo']);
    }

    public function test_species_filter_hides_lines_sold_only_for_other_species(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $path = urlencode('Purina > Pro Plan');

        $cat = array_column($this->getJson("/api/v1/brand-choices?path={$path}&species=cat")->json('choices'), 'name');
        $dog = array_column($this->getJson("/api/v1/brand-choices?path={$path}&species=dog")->json('choices'), 'name');

        $this->assertContains('Indoor Balance', $cat);
        $this->assertNotContains('Kitten', $cat);          // life stages are facets, not lines
        $this->assertNotContains('Small Breed', $cat);
        $this->assertNotContains('Savor', $cat);
        $this->assertContains('Small Breed', $dog);
        $this->assertContains('Prime Plus', $cat);      // cat Prime Plus exists, so it is open to both
    }

    public function test_seed_brands_command_builds_the_curated_ladder_and_is_repeatable(): void
    {
        $this->artisan('catalogue:seed-brands')->assertSuccessful();
        $count = BrandNode::count();

        $node = BrandNode::where('path_key', 'us|purina>pro plan>small breed')->first();
        $this->assertNotNull($node);
        $this->assertSame(['dog'], $node->species);
        $this->assertSame('line', $node->kind);
        $this->assertSame(['diet:clinical'], BrandNode::where('path_key', "us|hill's>prescription diet")->first()->default_tags);

        BrandNode::where('id', $node->id)->update(['name' => 'Small Breed (renamed by hand)']);
        $this->artisan('catalogue:seed-brands')->assertSuccessful();
        $this->assertSame($count, BrandNode::count());                       // nothing duplicated
        $this->assertSame('Small Breed (renamed by hand)', $node->fresh()->name); // nothing overwritten
    }

    public function test_life_stage_is_read_from_text_and_kept_off_the_ladder(): void
    {
        $this->assertSame(['life_stage:kitten'], LifeStageParser::tagKeys('Kitten'));
        $this->assertSame(['life_stage:adult-7plus'], LifeStageParser::tagKeys('ADULT 7+ | PRIME PLUS'));
        $this->assertSame(['life_stage:adult-7plus'], LifeStageParser::tagKeys('Senior 7+'));
        $this->assertSame(['life_stage:all'], LifeStageParser::tagKeys('All Life Stages'));
        $this->assertSame(['life_stage:senior'], LifeStageParser::tagKeys('Senior Cat Chicken'));
        $this->assertSame([], LifeStageParser::tagKeys('Indoor Balance'));

        $this->assertTrue(LifeStageParser::isOnlyLifeStage('Senior 7+'));
        $this->assertTrue(LifeStageParser::isOnlyLifeStage('All Life Stages'));
        $this->assertFalse(LifeStageParser::isOnlyLifeStage('Adult Indoor'));
        $this->assertFalse(LifeStageParser::isOnlyLifeStage('Adult 7+ | Prime Plus'));   // a line name rides along with it

        $this->import([$this->row(['brand' => 'Purina', 'line' => 'Purina ONE', 'name' => 'Chicken', 'meta' => ['variety' => 'Kitten']])]);
        $p = Product::first();
        $this->assertSame('Purina › Purina ONE', $p->path_text);
        $this->assertContains('life_stage:kitten', $p->tags->map(fn ($t) => "{$t->group}:{$t->slug}")->all());
    }

    public function test_aliases_resolve_to_the_existing_node_instead_of_creating_a_duplicate(): void
    {
        $proPlan = BrandNode::ensurePath([['name' => 'Purina'], ['name' => 'Pro Plan', 'aliases' => ['Purina Pro Plan']]]);
        $same = BrandNode::ensurePath([['name' => 'purina'], ['name' => 'Purina Pro Plan']]);
        $this->assertSame($proPlan->id, $same->id);
        $this->assertSame(2, BrandNode::count());

        // The curated ladder knows "Prime Plus" and its printed spelling even before any node exists.
        $a = BrandNode::ensurePath([['name' => 'Purina'], ['name' => 'Pro Plan'], ['name' => 'Adult 7+ | Prime Plus']]);
        $b = BrandNode::ensurePath([['name' => 'Purina'], ['name' => 'Pro Plan'], ['name' => 'Prime Plus']]);
        $this->assertSame('Prime Plus', $a->name);
        $this->assertSame($a->id, $b->id);
        $this->assertContains('life_stage:adult-7plus', $a->default_tags);
    }

    public function test_renaming_a_node_keeps_the_old_name_as_an_alias_and_products_follow(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->import([$this->row(['brand' => 'Tiki Cat', 'line' => 'After Dark'])]);
        $line = BrandNode::where('name', 'After Dark')->first();

        $this->patchJson("/api/v1/brand-nodes/{$line->id}", ['name' => 'Nightfall'])->assertOk()
            ->assertJsonPath('node.name', 'Nightfall')->assertJsonPath('node.aliases.0', 'After Dark');

        $p = Product::first();
        $this->assertSame('Tiki Cat › Nightfall', $p->path_text);
        $this->assertStringContainsString('after dark', $p->search_text);            // the old name still finds it
        $this->assertSame('us|tiki cat>nightfall', $line->fresh()->path_key);
        $this->assertSame($line->id, BrandNode::ensurePath([['name' => 'Tiki Cat'], ['name' => 'After Dark']])->id);
    }

    public function test_a_rename_that_collides_is_refused_and_asks_for_a_merge(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $a = BrandNode::ensurePath([['name' => 'Diamond']]);
        $b = BrandNode::ensurePath([['name' => 'Diamong']]);
        $this->patchJson("/api/v1/brand-nodes/{$b->id}", ['name' => 'Diamond'])->assertStatus(422);
    }

    public function test_merging_nodes_moves_products_and_children_and_keeps_every_old_name(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->import([
            $this->row(['brand' => 'Diamong', 'line' => 'Naturals', 'name' => 'Chicken']),
            $this->row(['brand' => 'Diamond', 'line' => 'Naturals', 'name' => 'Beef']),
            $this->row(['brand' => 'Diamong', 'line' => 'Extras', 'name' => 'Fish']),
        ]);
        $wrong = BrandNode::where('name', 'Diamong')->first();
        $right = BrandNode::where('name', 'Diamond')->first();

        $this->postJson("/api/v1/brand-nodes/{$wrong->id}/merge", ['into' => $right->id])->assertOk();

        $this->assertNull(BrandNode::where('name', 'Diamong')->first());
        $this->assertSame(['Diamond › Extras', 'Diamond › Naturals', 'Diamond › Naturals'], Product::orderBy('name')->get()->pluck('path_text')->sort()->values()->all());
        $this->assertContains('Diamong', $right->fresh()->aliases);
        $this->assertSame(1, BrandNode::where('name', 'Naturals')->count());            // the two "Naturals" folded together
        $this->postJson("/api/v1/brand-nodes/{$right->id}/merge", ['into' => $right->id])->assertStatus(422);
    }

    public function test_moving_a_node_rewrites_its_subtree_and_respects_the_depth_cap(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $line = BrandNode::ensurePath([['name' => 'Acme'], ['name' => 'Line'], ['name' => 'Sub']]);
        $other = BrandNode::ensurePath([['name' => 'Zed']]);
        $lineNode = $line->parent;

        $this->patchJson("/api/v1/brand-nodes/{$lineNode->id}", ['parent_id' => $other->id])->assertOk();
        $this->assertSame('us|zed>line>sub', $line->fresh()->path_key);
        $this->assertSame(3, $line->fresh()->depth);

        $deep = BrandNode::ensurePath([['name' => 'A'], ['name' => 'B'], ['name' => 'C'], ['name' => 'D'], ['name' => 'E']]);
        $this->patchJson("/api/v1/brand-nodes/{$lineNode->id}", ['parent_id' => $deep->parent_id])->assertStatus(422);   // would reach depth 6
        $this->patchJson("/api/v1/brand-nodes/{$lineNode->id}", ['parent_id' => $line->id])->assertStatus(422);          // not under itself
    }

    public function test_every_product_starts_as_version_one_and_corrections_do_not_add_versions(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->import([$this->row(['name' => 'Beef', 'ingredients' => 'Beef, water'])]);
        $p = Product::first();
        $this->assertSame(1, $p->versions()->count());
        $this->assertSame(1, $p->formula_version);

        $this->patchJson("/api/v1/products/{$p->id}", ['ingredients' => 'Beef, water, salt'])->assertOk();   // typo fix: no formula_change given
        $this->assertSame(1, $p->versions()->count());
        $this->assertSame('Beef, water, salt', $p->versions()->first()->ingredients);
        $this->assertNull($p->fresh()->formula_changed_at);
    }

    public function test_a_reformulation_keeps_the_old_recipe_as_history_and_the_ratings_stay_with_the_product(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->import([$this->row(['name' => 'Beef', 'ingredients' => 'Beef, water'])]);
        $p = Product::first();

        $this->patchJson("/api/v1/products/{$p->id}", ['ingredients' => 'Beef, water, tapioca', 'name' => 'Beef Entrée',
            'formula_change' => 'new_version', 'version_note' => 'New can design, new recipe'])->assertOk()
            ->assertJsonPath('product.formula_version', 2);

        $versions = $this->getJson("/api/v1/products/{$p->id}/versions")->assertOk()->json('versions');
        $this->assertCount(2, $versions);
        $this->assertSame(2, $versions[0]['version']);
        $this->assertTrue($versions[0]['is_current']);
        $this->assertSame('Beef, water, tapioca', $versions[0]['ingredients']);
        $this->assertSame('Beef Entrée', $versions[0]['name']);
        $this->assertSame('Beef, water', $versions[1]['ingredients']);          // the earlier recipe is still on record
        $this->assertSame('Beef', $versions[1]['name']);
        $this->assertFalse($versions[1]['is_current']);
        $this->assertNotNull($p->fresh()->formula_changed_at);
        $this->assertSame($p->id, Product::first()->id);                         // same product: barcode, ratings, pantry untouched
    }

    public function test_seed_brands_can_prune_empty_nodes_that_the_curated_file_no_longer_lists(): void
    {
        $this->artisan('catalogue:seed-brands')->assertSuccessful();
        BrandNode::ensurePath([['name' => 'Purina'], ['name' => 'Pro Plan'], ['name' => 'Kitten']]);    // an old life-stage rung
        $held = BrandNode::ensurePath([['name' => 'Purina'], ['name' => 'Pro Plan'], ['name' => 'Senior Stuff']]);
        $this->import([$this->row(['brand' => 'Purina', 'line' => 'Purina Pro Plan', 'name' => 'X'])]);
        Product::first()->update(['brand_node_id' => $held->id]);

        $this->artisan('catalogue:seed-brands --prune-empty --dry-run')->assertSuccessful();
        $this->assertNotNull(BrandNode::where('path_key', 'us|purina>pro plan>kitten')->first());   // dry run touches nothing

        $this->artisan('catalogue:seed-brands --prune-empty')->assertSuccessful();
        $this->assertNull(BrandNode::where('path_key', 'us|purina>pro plan>kitten')->first());
        $this->assertNotNull(BrandNode::where('path_key', 'us|purina>pro plan>senior stuff')->first());   // holds a product
        $this->assertNotNull(BrandNode::where('path_key', 'us|purina>pro plan>indoor balance')->first()); // curated
    }
}
