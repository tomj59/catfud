<?php

namespace Tests\Feature;

use App\Models\Advisory;
use App\Models\Product;
use App\Models\User;
use App\Support\AdvisoryImporter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdvisoryTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $gtin, string $brand, string $name): Product
    {
        return Product::create(['gtin' => $gtin, 'brand' => $brand, 'name' => $name, 'species' => 'cat', 'kind' => 'food', 'source' => 'test']);
    }

    private function entry(array $over = []): array
    {
        return $over + [
            'source_name' => 'Example Forum', 'source_url' => 'https://example.com/a1', 'published_at' => '2026-09-15',
            'source_text' => 'People discussed a texture change.', 'topic' => 'texture',
        ];
    }

    public function test_an_advisory_cannot_be_saved_without_a_source_name_and_link(): void
    {
        $r = app(AdvisoryImporter::class)->import([
            $this->entry(['source_name' => '']),
            $this->entry(['source_url' => 'not a url']),
            $this->entry(['source_text' => '']),
            $this->entry(),
        ]);

        $this->assertSame(1, $r['advisories']);
        $this->assertCount(3, $r['errors']);
        $this->assertDatabaseCount('advisories', 1);
    }

    public function test_matches_carry_basis_and_confidence(): void
    {
        $p = $this->product('0036000291452', 'Acme', 'Tuna');
        $this->product('4006381333931', 'Acme', 'Salmon');
        $this->product('0200000000011', 'Other', 'Crunchy Bites');

        app(AdvisoryImporter::class)->import([$this->entry(['matches' => [
            ['gtin' => '036000291452', 'lot_code' => 'LOT 9'],   // UPC-A spelling of the stored GTIN-13
            ['brand' => 'acme'],                                 // case-insensitive, lower confidence
            ['free_text' => 'crunchy'],
            ['gtin' => '9999999999994'],                          // unknown product
        ]])]);

        $by = fn ($name) => Product::where('name', $name)->first()->advisoryMatches ?? null;
        $m = \App\Models\AdvisoryMatch::with('product')->get()->keyBy(fn ($m) => $m->product->name);

        $this->assertSame('lot_code', $m['Tuna']->match_basis->value);
        $this->assertSame('high', $m['Tuna']->confidence->value);
        $this->assertSame('LOT 9', $m['Tuna']->match_detail);
        $this->assertSame('brand', $m['Salmon']->match_basis->value);
        $this->assertSame('medium', $m['Salmon']->confidence->value);
        $this->assertSame('free_text', $m['Crunchy Bites']->match_basis->value);
        $this->assertSame('low', $m['Crunchy Bites']->confidence->value);
        $this->assertCount(3, $m);
    }

    public function test_unmatched_specs_warn_but_the_advisory_is_kept(): void
    {
        $r = app(AdvisoryImporter::class)->import([$this->entry(['matches' => [['brand' => 'Nobody']]])]);

        $this->assertSame(1, $r['advisories']);
        $this->assertCount(1, $r['warnings']);
        $this->assertDatabaseCount('advisories', 1);
        $this->assertDatabaseCount('advisory_matches', 0);
    }

    public function test_reimporting_does_not_duplicate_or_reset_user_reviews(): void
    {
        $p = $this->product('0036000291452', 'Acme', 'Tuna');
        $user = User::factory()->create();
        $importer = app(AdvisoryImporter::class);
        $entries = [$this->entry(['matches' => [['gtin' => '0036000291452']]])];

        $importer->import($entries);
        $match = \App\Models\AdvisoryMatch::first();
        $user->advisoryMatchReviews()->create(['advisory_match_id' => $match->id, 'status' => 'dismissed']);
        $first = Advisory::first()->ingested_at;

        $importer->import($entries);

        $this->assertDatabaseCount('advisories', 1);
        $this->assertDatabaseCount('advisory_matches', 1);
        $this->assertDatabaseCount('advisory_match_reviews', 1);
        $this->assertEquals($first, Advisory::first()->ingested_at);
    }

    public function test_the_user_sees_only_advisories_about_their_pantry_and_each_is_attributed(): void
    {
        $inPantry = $this->product('0036000291452', 'Acme', 'Tuna');
        $other = $this->product('4006381333931', 'Zed', 'Salmon');
        app(AdvisoryImporter::class)->import([
            $this->entry(['matches' => [['gtin' => '0036000291452']]]),
            $this->entry(['source_url' => 'https://example.com/a2', 'source_name' => 'Newsletter', 'matches' => [['gtin' => '4006381333931']]]),
        ]);
        $user = User::factory()->create();
        $user->inventoryItems()->create(['product_id' => $inPantry->id]);
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/advisories')->assertOk();

        $res->assertJsonCount(1, 'advisories')
            ->assertJsonPath('advisories.0.attribution', 'Reported by Example Forum on Sep 15, 2026')
            ->assertJsonPath('advisories.0.source_url', 'https://example.com/a1')
            ->assertJsonPath('advisories.0.source_text', 'People discussed a texture change.')
            ->assertJsonPath('advisories.0.matches.0.product.name', 'Tuna')
            ->assertJsonPath('advisories.0.matches.0.review.status', 'new');
        $this->assertStringContainsString('not a statement of safety', $res->json('disclaimer'));

        $this->getJson('/api/advisories?all=1')->assertOk()->assertJsonCount(2, 'advisories');
    }

    public function test_a_users_confirm_or_dismiss_is_theirs_alone_and_leaves_the_advisory_untouched(): void
    {
        $p = $this->product('0036000291452', 'Acme', 'Tuna');
        app(AdvisoryImporter::class)->import([$this->entry(['matches' => [['gtin' => '0036000291452']]])]);
        $matchId = \App\Models\AdvisoryMatch::first()->id;
        $a = User::factory()->create();
        $b = User::factory()->create();
        foreach ([$a, $b] as $u) {
            $u->inventoryItems()->create(['product_id' => $p->id]);
        }
        $before = Advisory::first()->only(['source_name', 'source_url', 'source_text']);

        Sanctum::actingAs($a);
        $this->putJson("/api/advisory-matches/{$matchId}/review", ['status' => 'dismissed', 'note' => 'different lot'])
            ->assertOk()->assertJsonPath('match.review.status', 'dismissed')->assertJsonPath('match.review.note', 'different lot');
        $this->putJson("/api/advisory-matches/{$matchId}/review", ['status' => 'confirmed'])
            ->assertOk()->assertJsonPath('match.review.status', 'confirmed');
        $this->putJson("/api/advisory-matches/{$matchId}/review", ['status' => 'bogus'])->assertUnprocessable();
        $this->putJson('/api/advisory-matches/9999/review', ['status' => 'dismissed'])->assertNotFound();

        Sanctum::actingAs($b);
        $this->getJson('/api/advisories')->assertOk()->assertJsonPath('advisories.0.matches.0.review.status', 'new');

        $this->assertSame($before, Advisory::first()->only(['source_name', 'source_url', 'source_text']));
        $this->assertDatabaseCount('advisory_match_reviews', 1);
    }

    public function test_product_lookup_includes_attributed_advisories_and_the_disclaimer(): void
    {
        $this->product('0036000291452', 'Acme', 'Tuna');
        $this->product('4006381333931', 'Zed', 'Quiet');
        app(AdvisoryImporter::class)->import([$this->entry(['matches' => [['gtin' => '0036000291452']]])]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/products/lookup/036000291452')->assertOk()
            ->assertJsonCount(1, 'advisories')
            ->assertJsonPath('advisories.0.attribution', 'Reported by Example Forum on Sep 15, 2026')
            ->assertJsonPath('advisories.0.match.confidence', 'high');
        $this->getJson('/api/products/lookup/4006381333931')->assertOk()->assertJsonCount(0, 'advisories');
    }

    public function test_the_app_never_uses_verdict_language_in_advisory_output(): void
    {
        $p = $this->product('0036000291452', 'Acme', 'Tuna');
        app(AdvisoryImporter::class)->import([$this->entry(['matches' => [['gtin' => '0036000291452']]])]);
        $user = User::factory()->create();
        $user->inventoryItems()->create(['product_id' => $p->id]);
        Sanctum::actingAs($user);

        $json = strtolower($this->getJson('/api/advisories')->getContent());
        $own = preg_replace('/"source_text":"[^"]*"/', '', $json); // the source's own words are theirs; ours must be verdict-free

        foreach (['unsafe', 'dangerous', 'recall', 'avoid', 'toxic', 'do not feed'] as $word) {
            $this->assertStringNotContainsString($word, $own);
        }
    }

    public function test_sample_seed_loads_two_sample_advisories(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, Advisory::count());
        $this->assertSame(0, Advisory::where('source_name', 'not like', '%SAMPLE%')->count());
        $this->assertSame(3, \App\Models\AdvisoryMatch::count()); // 1 exact (lot) + 2 free-text
    }

    public function test_import_command_reports_exit_codes(): void
    {
        $good = sys_get_temp_dir().'/'.uniqid().'a.json';
        $bad = sys_get_temp_dir().'/'.uniqid().'b.json';
        file_put_contents($good, json_encode([$this->entry()]));
        file_put_contents($bad, json_encode([$this->entry(['source_url' => ''])]));

        $this->artisan('advisories:import', ['file' => $good])->expectsOutputToContain('advisories 1')->assertExitCode(0);
        $this->artisan('advisories:import', ['file' => $bad])->assertExitCode(1);
        $this->artisan('advisories:import', ['file' => '/nope.json'])->assertExitCode(1);
    }
}
