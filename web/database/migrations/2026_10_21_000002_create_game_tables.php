<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A game is "N vs N" without a field. It is visible to everyone only after ~30% of the players
        // (creator + friends who accepted) are in. Then the players chat and book a field later.
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('players_per_side');   // 3..11
            $table->unsignedTinyInteger('total_players');      // 2 × per side
            $table->unsignedTinyInteger('min_to_open');        // ceil(30% of total), creator included
            $table->string('level', 16)->nullable();
            $table->string('district', 32)->nullable();
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();   // preferred place (optional)
            $table->timestampTz('when_from')->nullable();       // preferred time window (optional)
            $table->timestampTz('when_to')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('status', 10)->index();              // App\Enums\GameStatus
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('previous_game_id')->nullable()->constrained('games')->nullOnDelete(); // "play again"
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('full_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('game_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10);          // invited | accepted | declined | joined | left | removed
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('side', 1)->nullable(); // A | B (players can pick teams in the chat later)
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['game_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreign('game_id')->references('id')->on('games')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropForeign(['game_id']));
        Schema::dropIfExists('game_players');
        Schema::dropIfExists('games');
    }
};
