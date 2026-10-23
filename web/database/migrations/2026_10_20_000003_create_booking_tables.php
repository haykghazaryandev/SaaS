<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->unsignedSmallInteger('duration_min');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->unsignedInteger('price')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();     // BB-4821
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 12)->index();    // App\Enums\BookingStatus
            $table->string('source', 12);             // App\Enums\BookingSource
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contact_name');
            $table->string('contact_phone', 20)->nullable();
            $table->unsignedBigInteger('game_id')->nullable()->index(); // foreign key added with the games table
            $table->foreignId('series_id')->nullable()->constrained('booking_series')->nullOnDelete();
            $table->unsignedInteger('price');
            $table->string('team_name')->nullable();
            $table->text('note_player')->nullable();
            $table->text('note_owner')->nullable();   // only the place sees it
            $table->string('decline_reason')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->string('cancelled_by', 8)->nullable(); // player | owner | system
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();
            $table->index(['field_id', 'starts_at']);
            $table->index(['user_id', 'starts_at']);
        });

        // The database itself makes double booking impossible:
        // two pending/accepted bookings of one field can never overlap in time.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement("ALTER TABLE bookings ADD COLUMN time_range tstzrange GENERATED ALWAYS AS (tstzrange(starts_at, ends_at, '[)')) STORED");
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_no_overlap EXCLUDE USING gist (field_id WITH =, time_range WITH &&) WHERE (status IN ('pending', 'accepted'))");
        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_time_order CHECK (ends_at > starts_at)');

        // History shown to the owner: created, accepted, changed (old → new), cancelled …
        Schema::create('booking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);
            $table->jsonb('changes')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_events');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('booking_series');
    }
};
