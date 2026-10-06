<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PersonalDataTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $gtin = '0036000291452', string $name = 'Tuna'): Product
    {
        return Product::create(['gtin' => $gtin, 'brand' => 'Acme', 'name' => $name, 'species' => 'cat', 'kind' => 'food', 'source' => 'test']);
    }

    public function test_pets_are_private_and_crud_works(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        Sanctum::actingAs($a);
        $id = $this->postJson('/api/v1/pets', ['name' => 'Miso', 'birth_date' => '2020-05-01'])
            ->assertCreated()->assertJsonPath('pet.species', 'cat')->json('pet.id');
        $this->patchJson("/api/v1/pets/{$id}", ['notes' => 'picky'])->assertOk()->assertJsonPath('pet.notes', 'picky');
        $this->getJson('/api/v1/pets')->assertOk()->assertJsonCount(1, 'pets');

        Sanctum::actingAs($b);
        $this->getJson('/api/v1/pets')->assertOk()->assertJsonCount(0, 'pets');
        $this->patchJson("/api/v1/pets/{$id}", ['notes' => 'hacked'])->assertNotFound();
        $this->deleteJson("/api/v1/pets/{$id}")->assertNotFound();

        Sanctum::actingAs($a);
        $this->deleteJson("/api/v1/pets/{$id}")->assertNoContent();
        $this->assertDatabaseCount('pets', 0);
    }

    public function test_inventory_add_by_either_barcode_spelling_accumulates_quantity(): void
    {
        $p = $this->product();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/inventory', ['gtin' => '036000291452', 'quantity' => 2, 'unit' => 'can'])
            ->assertCreated()->assertJsonPath('item.product.id', $p->id)->assertJsonPath('item.quantity', '2.00');
        $this->postJson('/api/v1/inventory', ['gtin' => '0036000291452', 'quantity' => 3])
            ->assertOk()->assertJsonPath('item.quantity', '5.00');
        $this->assertDatabaseCount('inventory_items', 1);
    }

    public function test_inventory_add_by_product_id_and_unknown_product(): void
    {
        $p = $this->product();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/inventory', ['product_id' => $p->id])->assertCreated();
        $this->postJson('/api/v1/inventory', ['gtin' => '4006381333931'])->assertNotFound();
        $this->postJson('/api/v1/inventory', ['gtin' => '036000291453'])->assertNotFound(); // bad check digit
        $this->postJson('/api/v1/inventory', [])->assertUnprocessable();
    }

    public function test_inventory_is_private_and_status_filter_works(): void
    {
        $p = $this->product();
        $q = $this->product('4006381333931', 'Salmon');
        $a = User::factory()->create();
        $b = User::factory()->create();

        Sanctum::actingAs($a);
        $itemId = $this->postJson('/api/v1/inventory', ['product_id' => $p->id])->json('item.id');
        $this->postJson('/api/v1/inventory', ['product_id' => $q->id, 'status' => 'low'])->assertCreated();
        $this->getJson('/api/v1/inventory?status=low')->assertOk()->assertJsonCount(1, 'items');
        $this->patchJson("/api/v1/inventory/{$itemId}", ['status' => 'out'])->assertOk()->assertJsonPath('item.status', 'out');
        $this->getJson('/api/v1/inventory?status=bogus')->assertUnprocessable();

        Sanctum::actingAs($b);
        $this->getJson('/api/v1/inventory')->assertOk()->assertJsonCount(0, 'items');
        $this->patchJson("/api/v1/inventory/{$itemId}", ['status' => 'stocked'])->assertNotFound();
        $this->deleteJson("/api/v1/inventory/{$itemId}")->assertNotFound();
    }

    public function test_ratings_upsert_and_cannot_use_another_users_pet(): void
    {
        $p = $this->product();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $petA = $a->pets()->create(['name' => 'Miso', 'species' => 'cat']);

        Sanctum::actingAs($a);
        $this->postJson('/api/v1/ratings', ['pet_id' => $petA->id, 'product_id' => $p->id, 'rating' => 'liked'])
            ->assertCreated()->assertJsonPath('rating.rating', 'liked');
        $this->postJson('/api/v1/ratings', ['pet_id' => $petA->id, 'product_id' => $p->id, 'rating' => 'refused', 'note' => 'sniffed, left'])
            ->assertOk()->assertJsonPath('rating.rating', 'refused');
        $this->assertDatabaseCount('ratings', 1);
        $this->postJson('/api/v1/ratings', ['pet_id' => $petA->id, 'product_id' => $p->id, 'rating' => 'loved'])
            ->assertUnprocessable();

        Sanctum::actingAs($b);
        $this->postJson('/api/v1/ratings', ['pet_id' => $petA->id, 'product_id' => $p->id, 'rating' => 'liked'])
            ->assertUnprocessable()->assertJsonValidationErrors(['pet_id']);
        $this->getJson('/api/v1/ratings')->assertOk()->assertJsonCount(0, 'ratings');
    }

    public function test_meal_offers_record_outcome_now_or_later_and_stay_private(): void
    {
        $p = $this->product();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $pet = $a->pets()->create(['name' => 'Miso', 'species' => 'cat']);

        Sanctum::actingAs($a);
        $id = $this->postJson('/api/v1/meal-offers', ['pet_id' => $pet->id, 'product_id' => $p->id, 'suggested_by_app' => true])
            ->assertCreated()->assertJsonPath('meal_offer.outcome', null)->assertJsonPath('meal_offer.suggested_by_app', true)
            ->json('meal_offer.id');
        $this->patchJson("/api/v1/meal-offers/{$id}", ['outcome' => 'ate_some'])->assertOk()->assertJsonPath('meal_offer.outcome', 'ate_some');
        $this->patchJson("/api/v1/meal-offers/{$id}", ['outcome' => 'yum'])->assertUnprocessable();
        $this->getJson("/api/v1/meal-offers?pet_id={$pet->id}")->assertOk()->assertJsonCount(1, 'meal_offers');

        Sanctum::actingAs($b);
        $this->patchJson("/api/v1/meal-offers/{$id}", ['outcome' => 'refused'])->assertNotFound();
        $this->getJson('/api/v1/meal-offers')->assertOk()->assertJsonCount(0, 'meal_offers');
    }
}
