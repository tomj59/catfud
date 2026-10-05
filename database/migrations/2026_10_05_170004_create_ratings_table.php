<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The scorecard of likes and dislikes: one rating per pet per product.
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('rating');                       // liked | neutral | refused
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['pet_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
