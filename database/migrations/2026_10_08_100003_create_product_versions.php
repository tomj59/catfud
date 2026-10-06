<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A product is the stable thing an owner knows (barcode, ratings, pantry). A version is one recipe/label of it:
        // makers reformulate and rename often, frequently keeping the barcode, so what is "on file" can change over time.
        Schema::create('product_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('name');
            $table->string('title_as_listed', 500)->nullable();
            $table->text('ingredients')->nullable();
            $table->json('nutrition')->nullable();
            $table->boolean('is_current')->default(true);
            $table->string('source')->nullable();               // seed | import | edit | backfill
            $table->foreignId('observed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('observed_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'version']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('formula_version')->default(1);
            $table->timestamp('formula_changed_at')->nullable();     // null until a recipe change is recorded
        });

        // Every product already on file becomes version 1 of itself.
        DB::statement("insert into product_versions (product_id, version, name, title_as_listed, ingredients, nutrition, is_current, source, observed_at, created_at, updated_at)
            select id, 1, name, title_as_listed, ingredients, nutrition, 1, 'backfill', coalesce(created_at, CURRENT_TIMESTAMP), CURRENT_TIMESTAMP, CURRENT_TIMESTAMP from products");
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['formula_version', 'formula_changed_at']);
        });
        Schema::dropIfExists('product_versions');
    }
};
