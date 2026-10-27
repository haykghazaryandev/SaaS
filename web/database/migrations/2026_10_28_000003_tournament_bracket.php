<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tournaments: who may register (rating range, how many players), the draw and the bracket (tournament_matches:
 * round 1 = the first round, slot = the match's place in its round; the winner moves to round + 1, slot / 2),
 * and a chat of the accepted players with the place (conversations.tournament_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_rating')->nullable()->after('options');
            $table->unsignedSmallInteger('max_rating')->nullable()->after('min_rating');
            $table->unsignedSmallInteger('max_players')->nullable()->after('max_rating');
            $table->timestampTz('drawn_at')->nullable()->after('status');      // the pairs are known (players see who they play)
        });
        Schema::create('tournament_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('round');                               // 1 = first round … last = the final
            $table->unsignedSmallInteger('slot');                               // 0-based within the round
            $table->foreignId('a_id')->nullable()->constrained('tournament_entries')->nullOnDelete();
            $table->foreignId('b_id')->nullable()->constrained('tournament_entries')->nullOnDelete();
            $table->foreignId('winner_id')->nullable()->constrained('tournament_entries')->nullOnDelete();
            $table->string('score', 20)->nullable();                            // "3:1"
            $table->boolean('live')->default(false);                            // being played now
            $table->timestamps();
            $table->unique(['tournament_id', 'round', 'slot']);
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('type', 16)->change();                               // direct | game | tournament
            $table->foreignId('tournament_id')->nullable()->unique()->after('game_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tournament_id');
        });
        Schema::dropIfExists('tournament_matches');
        Schema::table('tournaments', fn (Blueprint $t) => $t->dropColumn(['min_rating', 'max_rating', 'max_players', 'drawn_at']));
    }
};
