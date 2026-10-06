<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // "Adult 7+" is printed by several makers as its own stage, distinct from "Senior".
        if (! DB::table('tags')->where('group', 'life_stage')->where('slug', 'adult-7plus')->exists()) {
            DB::table('tags')->insert(['group' => 'life_stage', 'slug' => 'adult-7plus', 'label' => 'Adult 7+', 'sort' => 2]);
        }
    }

    public function down(): void
    {
        DB::table('tags')->where('group', 'life_stage')->where('slug', 'adult-7plus')->delete();
    }
};
