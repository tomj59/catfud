<?php

namespace Tests\Feature;

use App\Models\BrandNode;
use App\Models\Product;
use App\Models\Tag;
use App\Support\BrandTreeMapper;
use App\Support\Catalogue\CatalogueExporter;
use App\Support\Catalogue\CatalogueFiles;
use App\Support\Catalogue\CatalogueImporter;
use App\Support\Catalogue\CatalogueValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class CatalogueFilesTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private CatalogueFiles $files;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/catalogue-'.uniqid());
        $this->files = new CatalogueFiles('US', $this->dir);
        $this->files->write($this->files->vocabularyPath(), ['tags' => [
            ['group' => 'life_stage', 'slug' => 'adult', 'label' => 'Adult', 'sort' => 1], ['group' => 'life_stage', 'slug' => 'kitten', 'label' => 'Kitten', 'sort' => 2],
            ['group' => 'texture', 'slug' => 'pate', 'label' => 'Pâté', 'sort' => 1], ['group' => 'diet', 'slug' => 'clinical', 'label' => 'Clinical', 'sort' => 1],
        ]]);
        $this->files->write($this->files->ladderPath('acme'), ['reviewed' => true, 'ladder' => ['name' => 'Acme', 'kind' => 'manufacturer', 'children' => [
            ['name' => 'Tasty', 'kind' => 'brand', 'aliases' => ['Acme Tasty'], 'children' => [
                ['name' => 'Adult', 'kind' => 'line'], ['name' => 'Kitten', 'kind' => 'line', 'species' => ['cat']],
                ['name' => 'Old Recipe', 'kind' => 'line', 'status' => 'retired', 'status_on' => '2026-01-01', 'successor' => ['Acme', 'Tasty', 'Adult']],
            ]],
            ['name' => 'Rx', 'kind' => 'brand', 'tags' => ['diet:clinical']],
        ]]]);
        $this->files->write($this->files->productsPath('tasty'), ['reviewed' => true, 'ladder' => 'acme', 'brand' => 'Tasty', 'products' => [
            $this->row('Chicken', ['Acme', 'Tasty', 'Adult'], ['life_stage:adult', 'texture:pate'], 'k1'),
            $this->row('Tuna', ['Acme', 'Tasty', 'Kitten'], ['life_stage:kitten'], 'k2'),
        ]]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function row(string $name, array $path, array $tags, string $key, array $extra = []): array
    {
        return ['path' => $path, 'name' => $name, 'import_key' => $key, 'species' => 'cat', 'kind' => 'food', 'form' => 'wet', 'tags' => $tags, ...$extra];
    }

    private function importer(): CatalogueImporter
    {
        return new CatalogueImporter($this->files, new CatalogueValidator($this->files), app(BrandTreeMapper::class));
    }

    private function errors(?array $slugs = null): array
    {
        return array_column(array_filter((new CatalogueValidator($this->files))->check($slugs)['issues'], fn ($i) => $i['level'] === 'error'), 'message');
    }

    private function rewriteProducts(callable $edit): void
    {
        $f = $this->files->read($this->files->productsPath('tasty'));
        $this->files->write($this->files->productsPath('tasty'), $edit($f));
    }

    public function test_clean_files_validate_and_import_exactly_what_they_say(): void
    {
        $this->assertSame([], $this->errors());
        $this->importer()->importLadders();
        $r = $this->importer()->importProducts('tasty');
        $this->assertSame(2, $r['created']);

        $p = Product::where('import_key', 'k1')->first();
        $this->assertSame('Acme › Tasty › Adult', $p->path_text);
        $this->assertSame('Tasty', $p->brand);
        $this->assertSame('Adult', $p->line);
        $this->assertEqualsCanonicalizing(['life_stage:adult', 'texture:pate'], $p->tags->map(fn ($t) => $t->group.':'.$t->slug)->all());
        $this->assertSame('approved', $p->moderation_status);
    }

    public function test_import_is_repeatable_and_reports_nothing_changed(): void
    {
        $this->importer()->importProducts('tasty');
        $again = $this->importer()->importProducts('tasty');
        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 2, 'skipped' => 0], $again);

        $this->rewriteProducts(function ($f) {
            $f['products'][0]['tags'] = ['life_stage:adult'];                 // drop the texture tag
            $f['products'][0]['name'] = 'Chicken Feast';

            return $f;
        });
        $this->assertSame(1, $this->importer()->importProducts('tasty')['updated']);
        $p = Product::where('import_key', 'k1')->first();
        $this->assertSame('Chicken Feast', $p->name);
        $this->assertSame(['life_stage:adult'], $p->tags->map(fn ($t) => $t->group.':'.$t->slug)->all());   // exactly the listed tags
    }

    public function test_unreviewed_files_are_refused_unless_allowed(): void
    {
        $this->rewriteProducts(fn ($f) => [...$f, 'reviewed' => false]);
        $this->expectException(RuntimeException::class);
        try {
            $this->importer()->importProducts('tasty');
        } finally {
            $this->assertSame(0, Product::count());
        }
    }

    public function test_unreviewed_can_be_imported_when_asked(): void
    {
        $this->rewriteProducts(fn ($f) => [...$f, 'reviewed' => false]);
        $this->assertSame(2, $this->importer()->importProducts('tasty', allowUnreviewed: true)['created']);
    }

    public function test_the_validator_catches_what_the_importer_would_trip_on(): void
    {
        $this->rewriteProducts(function ($f) {
            $f['products'][] = $this->row('Nowhere', ['Acme', 'Tasty', 'Senior'], [], 'k3');                  // rung does not exist
            $f['products'][] = $this->row('Bad tag', ['Acme', 'Tasty', 'Adult'], ['life_stage:elder'], 'k4');
            $f['products'][] = $this->row('Dupe', ['Acme', 'Tasty', 'Adult'], [], 'k1');
            $f['products'][] = $this->row('No id', ['Acme', 'Tasty', 'Adult'], [], '', ['import_key' => null]);
            $f['products'][] = $this->row('Bad code', ['Acme', 'Tasty', 'Adult'], [], 'k5', ['gtin' => '1234567890123']);
            $f['products'][] = $this->row('Alias path', ['Acme', 'Acme Tasty', 'Adult'], [], 'k6');
            $f['products'][] = ['name' => 'No path', 'import_key' => 'k7'];

            return $f;
        });
        $e = implode("\n", $this->errors(['tasty']));
        foreach (['does not exist in any ladder file', 'Unknown tag "life_stage:elder"', 'Duplicate key:k1', 'Needs a gtin, or an import_key', 'not a valid 8/12/13/14', 'Needs "path"'] as $needle) {
            $this->assertStringContainsString($needle, $e);
        }
        $this->assertSame(0, Product::count());
    }

    public function test_the_validator_rejects_ladder_mistakes(): void
    {
        $l = $this->files->read($this->files->ladderPath('acme'));
        $l['ladder']['children'][0]['children'][] = ['name' => 'adult', 'kind' => 'line'];                         // same name as a sibling
        $l['ladder']['children'][0]['children'][] = ['name' => 'Seniors', 'aliases' => ['Kitten']];                 // alias that is another rung's name
        $l['ladder']['children'][1]['tags'] = ['diet:unknown'];
        $l['ladder']['children'][1]['status'] = 'gone';
        $l['ladder']['children'][1]['children'] = [['name' => 'A', 'children' => [['name' => 'B', 'children' => [['name' => 'C', 'children' => [['name' => 'D']]]]]]]];   // too deep
        $this->files->write($this->files->ladderPath('acme'), $l);

        $e = implode("\n", $this->errors());
        foreach (['is listed twice', 'is claimed by both', 'Unknown tag "diet:unknown"', 'status must be one of', 'Deeper than the 5-rung limit'] as $needle) {
            $this->assertStringContainsString($needle, $e);
        }
    }

    public function test_product_paths_never_create_rungs_and_a_missing_one_stops_the_import(): void
    {
        $this->importer()->importLadders();
        $before = BrandNode::count();
        $this->rewriteProducts(function ($f) {
            $f['products'][0]['path'] = ['Acme', 'Tasty', 'Brand New Line'];

            return $f;
        });
        $this->assertNotEmpty($this->errors(['tasty']));
        $this->assertSame($before, BrandNode::count());
    }

    public function test_ladder_import_applies_status_and_successor_and_only_fills_blanks_unless_overwriting(): void
    {
        $this->importer()->importLadders();
        $old = BrandNode::where('name', 'Old Recipe')->first();
        $this->assertSame('retired', $old->status);
        $this->assertSame('2026-01-01', $old->status_on->toDateString());
        $this->assertSame('Adult', $old->successor->name);

        $rx = BrandNode::where('name', 'Rx')->first();
        $rx->update(['notes' => 'edited by a person']);
        $l = $this->files->read($this->files->ladderPath('acme'));
        $l['ladder']['children'][1]['notes'] = 'from the file';
        $this->files->write($this->files->ladderPath('acme'), $l);

        $this->importer()->importLadders();
        $this->assertSame('edited by a person', $rx->fresh()->notes);                    // a person's edit survives
        $this->importer()->importLadders(overwrite: true);
        $this->assertSame('from the file', $rx->fresh()->notes);
    }

    public function test_a_product_a_person_has_audited_is_not_overwritten(): void
    {
        $this->importer()->importProducts('tasty');
        Product::where('import_key', 'k1')->first()->forceFill(['audit_status' => 'reviewed', 'last_edited_by' => null])->save();
        $this->rewriteProducts(function ($f) {
            $f['products'][0]['name'] = 'Renamed In File';

            return $f;
        });
        $r = $this->importer()->importProducts('tasty');
        $this->assertSame(1, $r['skipped']);
        $this->assertSame('Chicken', Product::where('import_key', 'k1')->value('name'));
    }

    public function test_the_database_exports_to_files_that_check_clean(): void
    {
        Tag::firstOrCreate(['group' => 'life_stage', 'slug' => 'adult'], ['label' => 'Adult', 'sort' => 1]);
        $this->importer()->importProducts('tasty');
        $out = new CatalogueFiles('US', $this->dir.'-export');
        (new CatalogueExporter($out))->export();

        $this->assertTrue(is_file($out->ladderPath('acme')));
        $this->assertTrue(is_file($out->productsPath('tasty')));
        $exported = $out->read($out->productsPath('tasty'));
        $this->assertFalse($exported['reviewed']);
        $this->assertSame(['Acme', 'Tasty', 'Adult'], collect($exported['products'])->firstWhere('name', 'Chicken')['path']);
        $this->assertSame([], array_column(array_filter((new CatalogueValidator($out))->check()['issues'], fn ($i) => $i['level'] === 'error'), 'message'));
        File::deleteDirectory($this->dir.'-export');
    }

    public function test_a_file_already_marked_reviewed_is_not_overwritten_by_an_export(): void
    {
        $this->importer()->importProducts('tasty');
        $out = new CatalogueFiles('US', $this->dir);                 // the same folder the reviewed fixtures live in
        $r = (new CatalogueExporter($out))->export();
        $this->assertContains($out->productsPath('tasty'), $r['kept']);
        $this->assertTrue($out->read($out->productsPath('tasty'))['reviewed']);
    }

    public function test_the_committed_catalogue_files_have_no_errors(): void
    {
        $r = (new CatalogueValidator(new CatalogueFiles('US')))->check();
        $this->assertSame([], array_values(array_map(fn ($i) => $i['file'].' '.$i['where'].' '.$i['message'], array_filter($r['issues'], fn ($i) => $i['level'] === 'error'))));
    }
}
