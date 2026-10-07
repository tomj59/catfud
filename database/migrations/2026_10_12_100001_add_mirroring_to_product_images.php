<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A picture can now be "registered" before it is downloaded: origin_url is where it came from, key names our local copy
        // (a hash of that URL, so products that share a picture share one file), and the file columns fill in once it is fetched.
        Schema::table('product_images', function (Blueprint $table) {
            $table->string('disk', 30)->nullable()->change();
            $table->string('path')->nullable()->change();
            $table->string('mime', 50)->nullable()->change();
            $table->unsignedInteger('bytes')->nullable()->change();
        });
        Schema::table('product_images', function (Blueprint $table) {
            $table->string('key', 40)->nullable()->index();
            $table->string('origin_url', 2048)->nullable();
            $table->string('status', 10)->default('ready');          // pending | ready | failed
            $table->timestamp('fetched_at')->nullable();
            $table->unsignedSmallInteger('fail_count')->default(0);
            $table->string('last_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_images', fn (Blueprint $t) => $t->dropColumn(['key', 'origin_url', 'status', 'fetched_at', 'fail_count', 'last_error']));
    }
};
