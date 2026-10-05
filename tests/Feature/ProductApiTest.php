<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $over = []): Product
    {
        return Product::create([
            'gtin' => '0036000291452', 'brand' => 'Acme', 'name' => 'Tuna Pâté', 'species' => 'cat',
            'kind' => 'food', 'source' => 'test', ...$over,
        ]);
    }

    public function test_lookup_finds_the_same_product_by_upc_a_or_ean_13(): void
    {
        $this->product();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/products/lookup/036000291452')->assertOk()
            ->assertJsonPath('product.name', 'Tuna Pâté')->assertJsonPath('product.upc_a', '036000291452');
        $this->getJson('/api/products/lookup/0036000291452')->assertOk()
            ->assertJsonPath('product.brand', 'Acme');
    }

    public function test_lookup_of_an_unknown_but_valid_code_returns_404_with_the_normalised_gtin(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/products/lookup/4006381333931')->assertNotFound()
            ->assertJsonPath('gtin', '4006381333931');
    }

    public function test_lookup_of_an_invalid_code_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/products/lookup/036000291453')->assertUnprocessable();
        $this->getJson('/api/products/lookup/123')->assertUnprocessable();
    }

    public function test_store_creates_a_product_with_a_normalised_gtin_and_provenance(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/products', [
            'gtin' => '036000291452', 'brand' => 'Acme', 'name' => 'Tuna Pâté',
            'nutrition' => ['crude_protein_min_pct' => '9'],
        ])->assertCreated()
            ->assertJsonPath('product.gtin', '0036000291452')
            ->assertJsonPath('product.source', 'user:'.$user->id)
            ->assertJsonPath('product.species', 'cat')
            ->assertJsonPath('product.nutrition.crude_protein_min_pct', '9');
    }

    public function test_store_rejects_a_bad_barcode_and_missing_fields(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/products', ['gtin' => '036000291453', 'brand' => 'A', 'name' => 'B'])
            ->assertUnprocessable();
        $this->postJson('/api/products', ['gtin' => '036000291452'])
            ->assertUnprocessable()->assertJsonValidationErrors(['brand', 'name']);
    }

    public function test_store_refuses_a_duplicate_even_in_the_other_barcode_spelling(): void
    {
        $this->product();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/products', ['gtin' => '036000291452', 'brand' => 'Other', 'name' => 'Dup'])
            ->assertStatus(409)->assertJsonPath('product.brand', 'Acme');
        $this->assertDatabaseCount('products', 1);
    }

    public function test_search_filters_by_text_and_kind(): void
    {
        $this->product();
        $this->product(['gtin' => '4006381333931', 'brand' => 'Zed', 'name' => 'Salmon Bites', 'kind' => 'treat']);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/products?q=salmon')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/products?kind=treat')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/products?kind=bogus')->assertUnprocessable();
        $this->getJson('/api/products?q=%25')->assertOk()->assertJsonCount(0, 'data'); // % is literal, not a wildcard
    }
}
