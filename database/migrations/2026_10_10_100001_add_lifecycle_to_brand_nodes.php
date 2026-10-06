<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_nodes', function (Blueprint $table) {
            // A line (or a whole brand) can be phased out. Nothing is ever deleted: shoppers still own it, and history matters.
            $table->string('status', 20)->default('active');               // active | phasing_out | discontinued
            $table->date('discontinued_on')->nullable();
            $table->string('status_confidence', 20)->nullable();           // confirmed | reported | rumoured
            $table->string('status_source', 500)->nullable();              // where we learned it (URL or note)
            $table->text('status_note')->nullable();
            $table->foreignId('successor_id')->nullable()->constrained('brand_nodes')->nullOnDelete();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('brand_nodes', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('successor_id');
            $table->dropColumn(['status', 'discontinued_on', 'status_confidence', 'status_source', 'status_note']);
        });
    }
};
