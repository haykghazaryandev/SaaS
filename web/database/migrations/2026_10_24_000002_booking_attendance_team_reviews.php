<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * After booking: reminders, "running late", arrival, no-show penalties, the booking's team, field ratings,
 * and an optional position for game players.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestampTz('arrived_at')->nullable();          // the place confirmed the team came
            $table->unsignedSmallInteger('late_minutes')->nullable(); // the player said "about N min late"
            $table->timestampTz('late_at')->nullable();
            $table->timestampTz('reminded_day_at')->nullable();     // 24 h reminder sent
            $table->timestampTz('reminded_hour_at')->nullable();    // 1 h reminder sent
            $table->timestampTz('not_here_asked_at')->nullable();   // "team not here" asked to the place
            $table->timestampTz('rating_asked_at')->nullable();     // "how was the field?" sent
            $table->string('invite_token', 24)->nullable()->unique(); // team invite link /join/{token}
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('booking_ban_until')->nullable();   // too many no-shows: no new requests until then
            $table->timestampTz('penalty_reset_at')->nullable();    // the admin reset: older no-shows do not count
        });

        // the team of a booking: friends the booker added (or invited by phone, until that person joins Bookball)
        Schema::create('booking_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('phone', 20)->nullable();                // an invite to someone without an account yet
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 8);                              // joined | invited | left
            $table->timestamps();
            $table->unique(['booking_id', 'user_id']);
            $table->unique(['booking_id', 'phone']);
            $table->index(['user_id', 'status']);
            $table->index('phone');
        });

        // "How was the field?" after a played game: one answer per person and booking
        Schema::create('venue_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('stars');                    // 1–5
            $table->string('comment', 500)->nullable();
            $table->timestamps();
            $table->unique(['booking_id', 'user_id']);
        });

        Schema::table('game_players', function (Blueprint $table) {
            $table->string('position', 16)->nullable();              // goalkeeper | defender | midfielder | forward (optional)
        });
    }

    public function down(): void
    {
        Schema::table('game_players', fn (Blueprint $t) => $t->dropColumn('position'));
        Schema::dropIfExists('venue_reviews');
        Schema::dropIfExists('booking_players');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['booking_ban_until', 'penalty_reset_at']));
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn(['arrived_at', 'late_minutes', 'late_at', 'reminded_day_at', 'reminded_hour_at', 'not_here_asked_at', 'rating_asked_at', 'invite_token']));
    }
};
