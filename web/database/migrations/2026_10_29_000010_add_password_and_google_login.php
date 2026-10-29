<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two more ways to log in: username + password (the username is users.username) and "Continue with Google".
 * Both start without a phone; the welcome page asks for it before the person can use the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable();
            $table->string('google_id', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password', 'google_id']);
        });
    }
};
