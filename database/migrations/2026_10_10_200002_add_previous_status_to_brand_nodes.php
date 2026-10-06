<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The status a rung had before its current one, set automatically on every change, so "was retired" survives a later "disabled". */
    public function up(): void
    {
        Schema::table('brand_nodes', fn (Blueprint $t) => $t->string('previous_status', 20)->nullable()->after('status'));
    }

    public function down(): void
    {
        Schema::table('brand_nodes', fn (Blueprint $t) => $t->dropColumn('previous_status'));
    }
};
