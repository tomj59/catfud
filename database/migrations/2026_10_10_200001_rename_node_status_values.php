<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A rung's status is active | retiring | retired | disabled.
     *   retiring: being phased out. Still offered, flagged, with a successor if there is one.
     *   retired: gone from the market. Not offered for new products; owners keep what they have.
     *   disabled: switched off by an admin (bad data, legal, a mistake), whatever the market is doing.
     * status_on is the date the current status took (or takes) effect.
     */
    public function up(): void
    {
        DB::table('brand_nodes')->where('status', 'phasing_out')->update(['status' => 'retiring']);
        DB::table('brand_nodes')->where('status', 'discontinued')->update(['status' => 'retired']);
        Schema::table('brand_nodes', fn (Blueprint $t) => $t->renameColumn('discontinued_on', 'status_on'));
    }

    public function down(): void
    {
        Schema::table('brand_nodes', fn (Blueprint $t) => $t->renameColumn('status_on', 'discontinued_on'));
        DB::table('brand_nodes')->where('status', 'retiring')->update(['status' => 'phasing_out']);
        DB::table('brand_nodes')->whereIn('status', ['retired', 'disabled'])->update(['status' => 'discontinued']);
    }
};
