<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Seeded products have no barcode until someone scans the real package and wires it up.
            $table->string('gtin', 13)->nullable()->change();

            $table->string('import_key')->nullable()->unique();   // stable identity for seeded rows (so re-imports never duplicate)
            $table->string('line')->nullable();                   // product line, e.g. "After Dark"
            $table->string('texture')->nullable();                // pate, shreds, gravy, ...
            $table->string('source_url', 2048)->nullable();       // where the data came from (a retailer page, say)
            $table->json('meta')->nullable();                     // import leftovers: pack size, price snapshot, sheet/row, data flags
            $table->string('audit_status')->default('unreviewed'); // unreviewed | reviewed | needs_changes
            $table->text('audit_notes')->nullable();
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index('audit_status');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['audit_status']);
            $table->dropConstrainedForeignId('last_edited_by');
            $table->dropUnique(['import_key']);
            $table->dropColumn(['import_key', 'line', 'texture', 'source_url', 'meta', 'audit_status', 'audit_notes']);
        });
    }
};
