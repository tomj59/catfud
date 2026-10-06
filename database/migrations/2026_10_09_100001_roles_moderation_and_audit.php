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
            $table->string('role', 20)->default('user');            // user | moderator | admin
        });
        // The pilot has one person. Whoever registered first runs the catalogue; others are promoted with
        // `php artisan user:role {email} {role}`.
        if ($first = DB::table('users')->min('id')) {
            DB::table('users')->where('id', $first)->update(['role' => 'admin']);
        }

        Schema::table('products', function (Blueprint $table) {
            // Everything already on file is public. User-added products start as 'pending' and are private to their contributor.
            $table->string('moderation_status', 20)->default('approved');   // approved | pending | needs_changes | rejected | merged
            $table->text('requested_path')->nullable();                     // the ladder a contributor typed, verbatim, when it did not exist
            $table->foreignId('merged_into_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            // 0 for the public catalogue; the contributor's user id while private. A barcode is unique per region within
            // one scope, so two people can each hold the same unknown barcode privately until one is reviewed.
            $table->unsignedBigInteger('gtin_scope')->default(0);

            $table->dropUnique(['region', 'gtin']);
            $table->unique(['region', 'gtin', 'gtin_scope']);
            $table->index(['moderation_status', 'created_by']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // null = a command or an import
            $table->string('action', 40);                                              // created | updated | deleted | merged | moderated ...
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('changes')->nullable();                                       // {field: [before, after]} or a small summary
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['region', 'gtin', 'gtin_scope']);
            $table->unique(['region', 'gtin']);
            $table->dropIndex(['moderation_status', 'created_by']);
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['moderation_status', 'requested_path', 'reviewed_at', 'review_note', 'gtin_scope']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
