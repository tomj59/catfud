<?php

namespace Tests\Feature;

use App\Models\Pet;
use App\Models\Product;
use App\Models\User;
use App\Support\MealSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MealSuggesterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Pet $pet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->pet = $this->user->pets()->create(['name' => 'Miso', 'species' => 'cat']);
    }

    private function stock(string $name, string $status = 'stocked', string $species = 'cat'): Product
    {
        static $n = 0;
        $n++;
        $body = '020'.str_pad((string) (500 + $n), 9, '0', STR_PAD_LEFT);
        $p = Product::create([
            'gtin' => $body.\App\Support\Gtin::checkDigitFor($body), 'brand' => 'Acme', 'name' => $name,
            'species' => $species, 'kind' => 'food', 'source' => 'test',
        ]);
        $this->user->inventoryItems()->create(['product_id' => $p->id, 'status' => $status]);

        return $p;
    }

    private function offer(Product $p, string $outcome, string $when): void
    {
        $this->user->mealOffers()->create([
            'pet_id' => $this->pet->id, 'product_id' => $p->id, 'offered_at' => $when, 'outcome' => $outcome,
        ]);
    }

    private function suggest(...$args): array
    {
        return app(MealSuggester::class)->suggest($this->pet->fresh(), ...$args);
    }

    public function test_nothing_in_stock(): void
    {
        $this->stock('Gone', 'out');
        $this->stock('Dog food', 'stocked', 'dog');

        $r = $this->suggest();

        $this->assertNull($r['suggestion']);
        $this->assertSame('nothing_in_stock', $r['none_reason']);
    }

    public function test_picks_the_product_with_the_best_recorded_history_and_explains_it(): void
    {
        $good = $this->stock('Good');
        $meh = $this->stock('Meh');
        foreach (['2026-09-01', '2026-09-03', '2026-09-05'] as $d) {
            $this->offer($good, 'ate_all', $d.' 12:00');
        }
        $this->offer($meh, 'refused', '2026-09-02 12:00');
        $this->offer($meh, 'ate_some', '2026-09-04 12:00');
        // last meal was `meh`, so `good` is not penalised for being repeated; make the last meal a third product
        $other = $this->stock('Other');
        $this->offer($other, 'refused', '2026-09-06 12:00');

        $r = $this->suggest();

        $this->assertSame('Good', $r['suggestion']['product']->name);
        $this->assertSame(3, $r['suggestion']['evidence']['ate_all']);
        $this->assertSame(3, $r['suggestion']['evidence']['outcomes_recorded']);
        $this->assertStringContainsString('Miso ate all 3 of the last 3 times you offered it', $r['suggestion']['statement']);
        $this->assertCount(2, $r['alternatives']);
    }

    public function test_only_the_last_five_outcomes_count(): void
    {
        $p = $this->stock('Old habits');
        $this->stock('Filler');
        foreach (range(1, 4) as $i) {
            $this->offer($p, 'refused', "2026-08-0{$i} 12:00");
        }
        foreach (range(1, 5) as $i) {
            $this->offer($p, 'ate_all', "2026-09-0{$i} 12:00");
        }

        $r = $this->suggest(excludeProductIds: [Product::where('name', 'Filler')->first()->id]);

        $this->assertSame(5, $r['suggestion']['evidence']['outcomes_recorded']);
        $this->assertSame(0, $r['suggestion']['evidence']['refused']);
    }

    public function test_the_product_from_the_last_meal_is_skipped_when_there_is_an_alternative(): void
    {
        $fav = $this->stock('Fav');
        $alt = $this->stock('Alt');
        $this->offer($fav, 'ate_all', '2026-09-01 12:00');
        $this->offer($fav, 'ate_all', '2026-09-02 12:00'); // last meal was Fav

        $r = $this->suggest();

        $this->assertSame('Alt', $r['suggestion']['product']->name);
        $this->assertTrue($r['alternatives'][0]['offered_last_meal']);
    }

    public function test_a_single_candidate_is_still_suggested_even_if_it_was_the_last_meal(): void
    {
        $only = $this->stock('Only');
        $this->offer($only, 'ate_all', '2026-09-01 12:00');

        $this->assertSame('Only', $this->suggest()['suggestion']['product']->name);
    }

    public function test_a_single_recorded_meal_reads_naturally(): void
    {
        $p = $this->stock('Once');
        $this->stock('Filler');
        $this->offer($p, 'ate_some', '2026-09-01 12:00');

        $r = $this->suggest(excludeProductIds: [Product::where('name', 'Filler')->first()->id]);

        $this->assertSame("Based on what you've recorded: Miso ate some of it the one time you offered it.", $r['suggestion']['statement']);
    }

    public function test_untried_products_are_labelled_as_trials(): void
    {
        $this->stock('New');

        $r = $this->suggest();

        $this->assertTrue($r['suggestion']['trial']);
        $this->assertStringContainsString('this would be a trial', $r['suggestion']['statement']);
        $this->assertSame(0.5, $r['suggestion']['score']);
    }

    public function test_the_users_own_rating_nudges_the_score(): void
    {
        $liked = $this->stock('Liked');
        $refused = $this->stock('Refused');
        $this->user->ratings()->create(['pet_id' => $this->pet->id, 'product_id' => $liked->id, 'rating' => 'liked']);
        $this->user->ratings()->create(['pet_id' => $this->pet->id, 'product_id' => $refused->id, 'rating' => 'refused']);

        $r = $this->suggest();

        $this->assertSame('Liked', $r['suggestion']['product']->name);
        $this->assertSame('refused', $r['alternatives'][0]['evidence']['your_rating']);
        $this->assertLessThan($r['suggestion']['score'], $r['alternatives'][0]['score']);
    }

    public function test_excluding_products_walks_through_the_options_then_reports_all_passed(): void
    {
        $a = $this->stock('A');
        $b = $this->stock('B');

        $first = $this->suggest()['suggestion']['product']->id;
        $second = $this->suggest(excludeProductIds: [$first])['suggestion']['product']->id;
        $this->assertNotSame($first, $second);

        $none = $this->suggest(excludeProductIds: [$a->id, $b->id]);
        $this->assertNull($none['suggestion']);
        $this->assertSame('all_passed', $none['none_reason']);
    }

    public function test_shuffle_is_repeatable_with_a_seed_and_favours_better_history_without_ruling_others_out(): void
    {
        $good = $this->stock('Good');
        $bad = $this->stock('Bad');
        foreach (range(1, 5) as $i) {
            $this->offer($good, 'ate_all', "2026-08-0{$i} 12:00");
            $this->offer($bad, 'refused', "2026-08-1{$i} 12:00");
        }
        $this->offer($this->stock('Last meal'), 'ate_all', '2026-09-30 12:00');

        $this->assertSame(
            $this->suggest(shuffle: true, seed: 7)['suggestion']['product']->id,
            $this->suggest(shuffle: true, seed: 7)['suggestion']['product']->id,
        );

        $tally = ['Good' => 0, 'Bad' => 0, 'Last meal' => 0];
        foreach (range(1, 300) as $seed) {
            $tally[$this->suggest(shuffle: true, seed: $seed)['suggestion']['product']->name]++;
        }

        $this->assertSame(0, $tally['Last meal']);          // skipped: it was the last meal
        $this->assertGreaterThan($tally['Bad'], $tally['Good']);
        $this->assertGreaterThan(0, $tally['Bad']);          // variety: a refused product can still come up
    }

    public function test_suggestion_wording_never_uses_health_claims(): void
    {
        $p = $this->stock('Words');
        $this->offer($p, 'ate_all', '2026-09-01 12:00');
        $this->stock('Other');

        $json = strtolower(json_encode($this->suggest(), JSON_UNESCAPED_UNICODE));

        foreach (['healthy', 'recommended', 'best for', 'nutritious', 'wellness'] as $word) {
            $this->assertStringNotContainsString($word, $json);
        }
        $this->assertStringContainsString('not advice about what is good for your pet', $json);
    }

    public function test_endpoint_is_private_and_validates(): void
    {
        $p = $this->stock('Endpoint');
        Sanctum::actingAs($this->user);

        $this->getJson("/api/v1/pets/{$this->pet->id}/suggestions")->assertOk()
            ->assertJsonPath('suggestion.product.name', 'Endpoint')->assertJsonPath('pet.name', 'Miso');
        $this->getJson("/api/v1/pets/{$this->pet->id}/suggestions?shuffle=1&exclude[]={$p->id}")->assertOk()
            ->assertJsonPath('none_reason', 'all_passed');
        $this->getJson("/api/v1/pets/{$this->pet->id}/suggestions?exclude=notanarray")->assertUnprocessable();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/pets/{$this->pet->id}/suggestions")->assertNotFound();
    }
}
