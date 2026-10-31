<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// paying before the end: what was on the bill when it was paid (sold) and what was added after (still to pay)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_items', fn (Blueprint $t) => $t->timestamp('paid_at')->nullable());
        // bills already paid: their items were sold then
        DB::statement('update booking_items set paid_at = b.paid_at from bookings b where b.id = booking_items.booking_id and b.paid_at is not null');
    }

    public function down(): void
    {
        Schema::table('booking_items', fn (Blueprint $t) => $t->dropColumn('paid_at'));
    }
};
