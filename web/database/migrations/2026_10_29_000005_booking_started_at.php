<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The desk starts a game ("Start game" on Now): the time is counted from then and only then is there a bill. Until
 * then a booking is just planned (no money). Games already stopped or paid keep their start as the booked start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestampTz('started_at')->nullable()->after('ends_at');
        });
        DB::table('bookings')->where(fn ($q) => $q->whereNotNull('stopped_at')->orWhereNotNull('paid_at'))->update(['started_at' => DB::raw('starts_at')]);
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('started_at'));
    }
};
