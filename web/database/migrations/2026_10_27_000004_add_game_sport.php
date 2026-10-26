<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Games of any sport: football N×N, ping-pong 1×1 and 2×2 (App\Enums\Sport::sides()). Existing games are football. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('sport', 16)->default('football')->index();
        });
    }

    public function down(): void
    {
        Schema::table('games', fn (Blueprint $table) => $table->dropColumn('sport'));
    }
};
