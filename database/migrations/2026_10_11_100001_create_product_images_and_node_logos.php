<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One current picture per product, with where it came from. products.image_url stays the URL the apps show.
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('disk', 30);
            $table->string('path');
            $table->string('mime', 50);
            $table->unsignedInteger('bytes');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('source', 30);                      // manufacturer | retailer | own_photo | other
            $table->string('source_url', 2048)->nullable();
            $table->string('licence', 255)->nullable();        // permission or licence note
            $table->string('attribution', 255)->nullable();    // credit line to show if one is required
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('brand_nodes', function (Blueprint $table) {
            $table->string('logo_path')->nullable();           // on the image disk; falls back to public/images/brands/{slug}.*
        });
    }

    public function down(): void
    {
        Schema::table('brand_nodes', fn (Blueprint $t) => $t->dropColumn('logo_path'));
        Schema::dropIfExists('product_images');
    }
};
