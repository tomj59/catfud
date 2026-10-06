<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_nodes', function (Blueprint $table) {
            // null = sold for every species; ["dog"] = dog only, so a cat owner's picker can hide it.
            $table->json('species')->nullable()->after('default_tags');
        });
    }

    public function down(): void
    {
        Schema::table('brand_nodes', function (Blueprint $table) {
            $table->dropColumn('species');
        });
    }
};
