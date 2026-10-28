<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Several players may ask for the same time (requests still waiting don't block each other); the place accepts one
 * (the others are declined then). Only accepted bookings can never overlap on a court.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_no_overlap');
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_no_overlap EXCLUDE USING gist (field_id WITH =, time_range WITH &&) WHERE (status = 'accepted')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE bookings DROP CONSTRAINT IF EXISTS bookings_no_overlap');
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_no_overlap EXCLUDE USING gist (field_id WITH =, time_range WITH &&) WHERE (status IN ('pending', 'accepted'))");
    }
};
