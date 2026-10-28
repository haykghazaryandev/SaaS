<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ZoneSpot's fee: a % of what a place takes for bookings, trainings and tournaments (5 by default, set per place by the admin). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->decimal('commission_pct', 4, 2)->default(5)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('venues', fn (Blueprint $table) => $table->dropColumn('commission_pct'));
    }
};
