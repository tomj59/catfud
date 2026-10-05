<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Raw material for the history-based suggester: what was offered, and what happened.
        Schema::create('meal_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamp('offered_at');
            $table->string('outcome')->nullable();          // ate_all | ate_some | refused (null until recorded)
            $table->boolean('suggested_by_app')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['pet_id', 'offered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_offers');
    }
};
