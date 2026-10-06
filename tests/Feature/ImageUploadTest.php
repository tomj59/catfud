<?php

namespace Tests\Feature;

use App\Models\BrandNode;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImageUploadTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        $this->actingAs(User::factory()->admin()->create());
        $node = BrandNode::ensurePath([['name' => 'Purina'], ['name' => 'Pro Plan']]);
        $p = Product::create(['brand' => 'Purina', 'name' => 'Chicken', 'species' => 'cat', 'kind' => 'food', 'source' => 'test', 'moderation_status' => 'approved']);
        app(\App\Support\BrandTreeMapper::class)->place($p, $node);
        auth()->logout();

        return $p;
    }

    private function meta(array $over = []): array
    {
        return ['image' => UploadedFile::fake()->image('can.png', 400, 400), 'source' => 'manufacturer', 'licence' => 'Press kit', 'attribution' => 'Purina', ...$over];
    }

    public function test_staff_can_set_replace_and_remove_a_product_image_with_its_source(): void
    {
        Storage::fake('public');
        $p = $this->product();
        Sanctum::actingAs(User::factory()->moderator()->create());

        $r = $this->post("/api/v1/admin/products/{$p->id}/image", $this->meta(), ['Accept' => 'application/json'])->assertOk();
        $url = $r->json('product.image.url');
        $this->assertSame($url, $r->json('product.image_url'));
        $this->assertSame('manufacturer', $r->json('product.image.source'));
        $this->assertSame('Press kit', $r->json('product.image.licence'));
        $first = ProductImage::first()->path;
        Storage::disk('public')->assertExists($first);

        $this->post("/api/v1/admin/products/{$p->id}/image", $this->meta(['source' => 'own_photo']), ['Accept' => 'application/json'])->assertOk()->assertJsonPath('product.image.source', 'own_photo');
        Storage::disk('public')->assertMissing($first);                       // the old file is not left behind
        $this->assertSame(1, ProductImage::count());

        $this->deleteJson("/api/v1/admin/products/{$p->id}/image")->assertOk()->assertJsonPath('product.image_url', null)->assertJsonPath('product.image', null);
        $this->assertSame(0, ProductImage::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_upload_is_validated_and_users_cannot_do_it(): void
    {
        Storage::fake('public');
        $p = $this->product();

        Sanctum::actingAs(User::factory()->create());
        $this->post("/api/v1/admin/products/{$p->id}/image", $this->meta(), ['Accept' => 'application/json'])->assertForbidden();

        Sanctum::actingAs(User::factory()->moderator()->create());
        $h = ['Accept' => 'application/json'];
        $this->post("/api/v1/admin/products/{$p->id}/image", $this->meta(['image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]), $h)->assertUnprocessable();
        $this->post("/api/v1/admin/products/{$p->id}/image", $this->meta(['image' => UploadedFile::fake()->image('tiny.png', 50, 50)]), $h)->assertUnprocessable();
        $this->post("/api/v1/admin/products/{$p->id}/image", $this->meta(['source' => 'found-it']), $h)->assertUnprocessable();
        $this->post("/api/v1/admin/products/{$p->id}/image", $this->meta(['image' => UploadedFile::fake()->image('big.png', 400, 400)->size(6000)]), $h)->assertUnprocessable();
        $this->assertSame(0, ProductImage::count());
    }

    public function test_the_image_change_is_audited_and_the_image_shows_in_the_public_product(): void
    {
        Storage::fake('public');
        $p = $this->product();
        $mod = User::factory()->moderator()->create();
        Sanctum::actingAs($mod);
        $this->post("/api/v1/admin/products/{$p->id}/image", $this->meta(), ['Accept' => 'application/json'])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['subject_type' => 'Product', 'subject_id' => $p->id, 'user_id' => $mod->id, 'action' => 'updated']);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/products/{$p->id}")->assertOk()->assertJsonPath('product.image.attribution', 'Purina');
    }

    public function test_a_node_logo_reaches_the_picker_and_replaces_the_old_file(): void
    {
        Storage::fake('public');
        $this->product();
        $node = BrandNode::where('name', 'Pro Plan')->first();
        Sanctum::actingAs(User::factory()->admin()->create());

        $r = $this->post("/api/v1/admin/nodes/{$node->id}/logo", ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)], ['Accept' => 'application/json'])->assertOk();
        $logo = $r->json('node.logo');
        $this->assertNotNull($logo);
        $first = $node->fresh()->logo_path;

        $choices = collect($this->getJson('/api/v1/brand-choices?path='.urlencode('Purina'))->assertOk()->json('choices'))->firstWhere('name', 'Pro Plan');
        $this->assertSame($logo, $choices['logo']);

        $this->post("/api/v1/admin/nodes/{$node->id}/logo", ['logo' => UploadedFile::fake()->image('new.png', 200, 200)], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing($first);

        $this->deleteJson("/api/v1/admin/nodes/{$node->id}/logo")->assertOk()->assertJsonPath('node.logo', null);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
