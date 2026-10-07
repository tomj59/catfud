<?php

namespace Tests\Feature;

use App\Models\BrandNode;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Support\ImageMirror;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImageMirrorTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://93.184.216.34/cdn/can.png';   // a public address, so no DNS lookup is needed

    private function product(string $name = 'Chicken'): Product
    {
        return Product::create(['brand' => 'X', 'name' => $name, 'species' => 'cat', 'kind' => 'food', 'source' => 'test', 'moderation_status' => 'approved']);
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(8, 6);
        ob_start();
        imagepng($im);

        return ob_get_clean();
    }

    public function test_registering_keys_the_picture_records_credit_and_points_the_product_at_our_copy(): void
    {
        $p = $this->product();
        $changed = app(ImageMirror::class)->register($p, self::ORIGIN.'#frag', 'https://www.example-pets.com/products/chicken');

        $key = ImageMirror::keyFor(self::ORIGIN);
        $this->assertTrue($changed);
        $this->assertSame("/api/v1/img/{$key}", $p->fresh()->image_url);
        $row = ProductImage::first();
        $this->assertSame('pending', $row->status);
        $this->assertSame(self::ORIGIN, $row->origin_url);
        $this->assertSame('https://www.example-pets.com/products/chicken', $row->source_url);
        $this->assertSame('Image from example-pets.com', $row->attribution);
        $this->assertFalse(app(ImageMirror::class)->register($p, self::ORIGIN, 'https://www.example-pets.com/products/chicken'));   // repeatable
    }

    public function test_first_request_downloads_once_and_later_requests_come_from_our_storage(): void
    {
        Storage::fake('public');
        Http::fake([self::ORIGIN => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);
        $p = $this->product();
        app(ImageMirror::class)->register($p, self::ORIGIN);
        $key = ImageMirror::keyFor(self::ORIGIN);

        $r = $this->get("/api/v1/img/{$key}")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('immutable', $r->headers->get('Cache-Control'));
        $this->get("/api/v1/img/{$key}", ['If-None-Match' => '"'.$key.'"'])->assertStatus(304);
        $this->get("/api/v1/img/{$key}")->assertOk();

        Http::assertSentCount(1);
        $row = ProductImage::first();
        $this->assertSame('ready', $row->status);
        $this->assertSame(8, $row->width);
        Storage::disk('public')->assertExists("mirror/".substr($key, 0, 2)."/{$key}.png");
    }

    public function test_products_sharing_a_picture_share_one_file_and_one_download(): void
    {
        Storage::fake('public');
        Http::fake([self::ORIGIN => Http::response($this->png(), 200)]);
        [$a, $b] = [$this->product('A'), $this->product('B')];
        $m = app(ImageMirror::class);
        $m->register($a, self::ORIGIN);
        $m->register($b, self::ORIGIN);
        $m->fetch(ImageMirror::keyFor(self::ORIGIN));

        $this->assertSame(1, ProductImage::distinct('path')->count('path'));
        $this->assertSame(['ready', 'ready'], ProductImage::pluck('status')->all());
        Http::assertSentCount(1);
    }

    public function test_anything_that_is_not_a_real_picture_or_not_a_public_address_is_refused(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://93.184.216.34/page.png' => Http::response('<html>nope</html>', 200, ['Content-Type' => 'image/png']),
            'https://93.184.216.34/art.png' => Http::response('<svg xmlns="http://www.w3.org/2000/svg"/>', 200),
        ]);
        $m = app(ImageMirror::class);
        foreach (['https://93.184.216.34/page.png', 'https://93.184.216.34/art.png', 'http://127.0.0.1/secret.png', 'http://10.0.0.5/x.png', 'ftp://93.184.216.34/x.png'] as $i => $url) {
            $m->register($this->product("P{$i}"), $url);
            $this->assertFalse($m->fetch(ImageMirror::keyFor($url)), $url);
        }
        $this->assertSame(5, ProductImage::where('status', 'failed')->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->get('/api/v1/img/'.ImageMirror::keyFor('http://127.0.0.1/secret.png'))->assertNotFound();
    }

    public function test_unknown_keys_are_not_found_and_never_fetch_anything(): void
    {
        Http::fake();
        $this->get('/api/v1/img/'.str_repeat('a', 32))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_a_staff_upload_is_never_replaced_by_a_later_import(): void
    {
        Storage::fake('public');
        $p = $this->product();
        Sanctum::actingAs(User::factory()->moderator()->create());
        $this->post("/api/v1/admin/products/{$p->id}/image", ['image' => UploadedFile::fake()->image('a.png', 400, 400), 'source' => 'own_photo'], ['Accept' => 'application/json'])->assertOk();
        $before = $p->fresh()->image_url;

        $this->assertFalse(app(ImageMirror::class)->register($p->fresh(), self::ORIGIN));
        $this->assertSame($before, $p->fresh()->image_url);
    }

    public function test_the_batch_command_copies_pending_pictures_and_reports_failures(): void
    {
        Storage::fake('public');
        Http::fake([self::ORIGIN => Http::response($this->png(), 200), 'https://93.184.216.34/gone.png' => Http::response('', 404)]);
        $m = app(ImageMirror::class);
        $m->register($this->product('A'), self::ORIGIN);
        $m->register($this->product('B'), 'https://93.184.216.34/gone.png');

        $this->artisan('catalogue:mirror-images', ['--delay' => 0])->expectsOutputToContain('1 copied, 1 failed, out of 2')->assertSuccessful();
        $this->assertSame(['failed', 'ready'], ProductImage::orderBy('status')->pluck('status')->all());
        $this->artisan('catalogue:mirror-images', ['--delay' => 0])->expectsOutputToContain('out of 0');          // failed ones wait for --retry-failed
    }
}
