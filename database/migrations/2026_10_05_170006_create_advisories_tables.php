<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Public Advisory: what a named source said, and when. The app never writes advisory text of its own,
        // and an advisory cannot be saved without a source name and link.
        Schema::create('advisories', function (Blueprint $table) {
            $table->id();
            $table->string('source_name');
            $table->string('source_url');
            $table->date('published_at')->nullable();
            $table->timestamp('ingested_at');
            $table->text('source_text');                    // the source's own wording
            $table->string('topic')->nullable();
            $table->timestamps();
        });

        // Global: which products an advisory may relate to, with how it was matched and how sure we are.
        Schema::create('advisory_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advisory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('match_basis');                  // upc | brand | lot_code | free_text
            $table->string('confidence');                   // high | medium | low
            $table->string('match_detail')->nullable();     // e.g. the lot or date code the source gave
            $table->timestamps();

            $table->unique(['advisory_id', 'product_id']);
        });

        // Per user: the user's own decision about a match. Dismissal never alters the advisory.
        Schema::create('advisory_match_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('advisory_match_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('new');       // new | confirmed | dismissed
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'advisory_match_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisory_match_reviews');
        Schema::dropIfExists('advisory_matches');
        Schema::dropIfExists('advisories');
    }
};
