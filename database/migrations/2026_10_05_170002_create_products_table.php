<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global, shared product catalogue. Nutrition is stored exactly as printed on the label; nothing is computed.
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('gtin', 13)->unique();          // canonical GTIN-13 (a UPC-A has a leading 0)
            $table->string('brand');
            $table->string('name');
            $table->string('species')->default('cat');
            $table->string('kind')->default('food');        // food | treat
            $table->string('form')->nullable();             // wet, dry, freeze-dried, ...
            $table->text('description')->nullable();
            $table->text('ingredients')->nullable();
            $table->json('nutrition')->nullable();          // label values as printed (guaranteed analysis etc.)
            $table->string('image_url')->nullable();
            $table->string('source');                       // where this record came from (provenance)
            $table->timestamp('last_verified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['brand', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
