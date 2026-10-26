<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Club desk ("Now"): a game is stopped when they finish (stopped_at; the time charged is then fixed), and a walk-in
 * started at the desk has no booked length (open_end: charged for the time played).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestampTz('stopped_at')->nullable();
            $table->boolean('open_end')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn(['stopped_at', 'open_end']));
    }
};
