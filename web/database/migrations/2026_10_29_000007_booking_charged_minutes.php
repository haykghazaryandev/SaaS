<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The minutes a stopped game was charged for (the table itself is freed at the stop, so its length can be shorter). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedSmallInteger('charged_minutes')->nullable()->after('stopped_at');
        });
        // games stopped before: their length was the time charged
        DB::table('bookings')->whereNotNull('stopped_at')->update(['charged_minutes' => DB::raw('(EXTRACT(EPOCH FROM (ends_at - starts_at)) / 60)::int')]);
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('charged_minutes'));
    }
};
