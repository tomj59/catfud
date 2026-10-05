<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\ProductImporter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    private function tmp(string $name, string $content): string
    {
        $path = sys_get_temp_dir().'/'.uniqid().'_'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    public function test_csv_import_creates_then_updates_and_is_idempotent(): void
    {
        $csv = "gtin,brand,name,kind,nutrition_crude_protein_min_pct\n036000291452,Acme,Tuna,food,9\n4006381333931,Zed,Bites,treat,\n";
        $path = $this->tmp('p.csv', $csv);

        $first = app(ProductImporter::class)->importFile($path);
        $this->assertSame(['created' => 2, 'updated' => 0, 'skipped' => 0, 'errors' => []], $first);

        $again = app(ProductImporter::class)->importFile($path);
        $this->assertSame(2, $again['updated']);
        $this->assertDatabaseCount('products', 2);

        $tuna = Product::where('gtin', '0036000291452')->first();
        $this->assertSame(['crude_protein_min_pct' => '9'], $tuna->nutrition);
        $this->assertSame('import:'.basename($path), $tuna->source);
        $this->assertNull(Product::where('gtin', '4006381333931')->first()->nutrition); // blank nutrition cell is not stored
    }

    public function test_upc_a_and_ean_13_rows_collapse_to_one_product(): void
    {
        $path = $this->tmp('p.csv', "gtin,brand,name\n036000291452,Acme,Tuna\n0036000291452,Acme,Tuna v2\n");

        $r = app(ProductImporter::class)->importFile($path);

        $this->assertSame(1, $r['created']);
        $this->assertSame(1, $r['updated']);
        $this->assertDatabaseCount('products', 1);
        $this->assertSame('Tuna v2', Product::first()->name);
    }

    public function test_bad_rows_are_reported_and_skipped(): void
    {
        $path = $this->tmp('p.csv', "gtin,brand,name,kind\n036000291453,Acme,BadCheck,food\n036000291452,,NoBrand,food\n4006381333931,Zed,Ok,snack\n4006381333931,Zed,Ok,food\n");

        $r = app(ProductImporter::class)->importFile($path);

        $this->assertSame(1, $r['created']);
        $this->assertCount(3, $r['errors']);
        $this->assertSame([1, 2, 3], array_column($r['errors'], 'row'));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $path = $this->tmp('p.csv', "gtin,brand,name\n036000291452,Acme,Tuna\n");

        $r = app(ProductImporter::class)->importFile($path, dryRun: true);

        $this->assertSame(1, $r['created']);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_json_import_with_nested_nutrition(): void
    {
        $json = json_encode(['products' => [[
            'gtin' => '036000291452', 'brand' => 'Acme', 'name' => 'Tuna', 'source' => 'vendor-sheet',
            'nutrition' => ['crude_fat_min_pct' => '4'],
        ]]]);

        $r = app(ProductImporter::class)->importFile($this->tmp('p.json', $json));

        $this->assertSame(1, $r['created']);
        $p = Product::first();
        $this->assertSame('vendor-sheet', $p->source);
        $this->assertSame(['crude_fat_min_pct' => '4'], $p->nutrition);
    }

    public function test_artisan_command_succeeds_and_fails_with_exit_codes(): void
    {
        $good = $this->tmp('g.csv', "gtin,brand,name\n036000291452,Acme,Tuna\n");
        $bad = $this->tmp('b.csv', "gtin,brand,name\n123,Acme,Tuna\n");

        $this->artisan('products:import', ['file' => $good])->expectsOutputToContain('created 1')->assertExitCode(0);
        $this->artisan('products:import', ['file' => $bad])->assertExitCode(1);
        $this->artisan('products:import', ['file' => '/nope.csv'])->assertExitCode(1);
        $this->artisan('products:import', ['file' => $this->tmp('x.txt', 'hi')])->assertExitCode(1);
    }

    public function test_seeder_loads_20_sample_products_with_valid_codes(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(20, Product::count());
        $this->assertSame(0, Product::where('source', '!=', 'sample-seed')->count());
        $this->assertSame(20, Product::whereRaw("gtin LIKE '020%'")->count());
        $this->assertSame(15, Product::where('kind', 'food')->count());
        $this->assertSame(5, Product::where('kind', 'treat')->count());
    }
}
