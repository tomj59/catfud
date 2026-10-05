<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('region', 8)->default('US');
        });

        // Brand tree: manufacturer > brand > line > sub-line > sub-sub-line, any level optional, depth capped at 5.
        Schema::create('brand_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('region', 8)->default('US');
            $table->foreignId('parent_id')->nullable()->constrained('brand_nodes')->restrictOnDelete();
            $table->string('name');
            $table->string('kind')->nullable();         // manufacturer | brand | line | subline ... a hint, not a rule
            $table->unsignedTinyInteger('depth');       // 1 = root
            $table->string('path_key')->unique();       // "us|purina>pro plan>complete essentials" (lower-case, one node per path)
            $table->json('aliases')->nullable();        // renamed lines, retailer spellings, "Purina Pro Plan"
            $table->json('default_tags')->nullable();   // e.g. ["diet:clinical"] applied to products placed under this node
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['region', 'parent_id']);
        });

        // Facets: texture, medium (what it sits in), life stage, diet. Vocabulary rows, not parts of a name.
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('group');
            $table->string('slug');
            $table->string('label');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unique(['group', 'slug']);
        });

        Schema::create('product_tag', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->primary(['product_id', 'tag_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['gtin']);               // a barcode is unique within a region, not globally
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('region', 8)->default('US');
            $table->foreignId('brand_node_id')->nullable()->constrained('brand_nodes')->nullOnDelete();
            $table->string('path_text', 600)->nullable();    // "Purina › Pro Plan › Complete Essentials" (cache)
            $table->text('search_text')->nullable();         // path + aliases, lower-case, for type-ahead (cache)
            $table->string('title_as_listed', 500)->nullable(); // verbatim retailer/manufacturer title
            $table->unique(['region', 'gtin']);
            $table->index(['region', 'brand_node_id']);
        });

        // A barcode belongs to a pack (single can, 12-pack, case), not to a product: extra barcodes live here.
        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('region', 8)->default('US');
            $table->string('gtin', 13);
            $table->string('pack_label')->nullable();   // "24-can case", "single 3 oz can"
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['region', 'gtin']);
        });

        $now = now();
        $rows = [];
        $add = function (string $group, array $items) use (&$rows) {
            foreach ($items as $i => [$slug, $label]) {
                $rows[] = ['group' => $group, 'slug' => $slug, 'label' => $label, 'sort' => $i];
            }
        };
        $add('texture', [['pate', 'Pâté'], ['mousse', 'Mousse'], ['shreds', 'Shreds'], ['chunks', 'Chunks'], ['flaked', 'Flaked'],
            ['minced', 'Minced'], ['morsels', 'Morsels'], ['cuts', 'Cuts'], ['sliced', 'Sliced'], ['loaf', 'Loaf']]);
        $add('medium', [['gravy', 'Gravy'], ['broth', 'Broth'], ['sauce', 'Sauce'], ['jelly', 'Jelly'], ['aspic', 'Aspic'], ['stew', 'Stew']]);
        $add('life_stage', [['kitten', 'Kitten'], ['adult', 'Adult'], ['senior', 'Senior'], ['all', 'All life stages']]);
        // "Clinical" is a deliberate workaround for vet-diet variants until we learn how owners of special-needs cats
        // prefer to find them. It labels how the manufacturer sells the product; the app makes no health claim.
        $add('diet', [['clinical', 'Clinical (vet diet)']]);
        DB::table('tags')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['region', 'brand_node_id']);
            $table->dropUnique(['region', 'gtin']);
            $table->dropConstrainedForeignId('brand_node_id');
            $table->dropColumn(['region', 'path_text', 'search_text', 'title_as_listed']);
            $table->unique('gtin');
        });
        Schema::dropIfExists('product_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('brand_nodes');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('region'));
    }
};
