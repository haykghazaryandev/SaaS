<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Tournament divisions: one tournament (the parent: its link lists the divisions) with up to 6 divisions under it,
 *   each a full tournament of its own (a rating range, players, fee, bracket, chat): tournaments.parent_id + division.
 * - Chats with a place: a player writes to the place, its staff answer (conversations.venue_id, type 'venue').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('venue_id')->constrained('tournaments')->cascadeOnDelete();
            $table->string('division', 40)->nullable()->after('title');        // "Amateur" (the title then is "Cup · Amateur")
        });
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('venue_id')->nullable()->after('tournament_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', fn (Blueprint $table) => $table->dropConstrainedForeignId('venue_id'));
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('division');
        });
    }
};
