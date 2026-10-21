<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Player levels (beginner / intermediate / advanced) were removed from the product (2026-10-22). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('level'));
        Schema::table('games', fn (Blueprint $t) => $t->dropColumn('level'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->string('level', 16)->nullable());
        Schema::table('games', fn (Blueprint $t) => $t->string('level', 16)->nullable());
    }
};
