<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Telegram account counts for automatic login (Mini App, one-tap bot login) only when its own phone number,
 * shared with the bot, matched the Bookball account (telegram_verified_at). "Connect Telegram" used to link any
 * Telegram to whoever was logged in; those links stay for notifications but must be confirmed before logging in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->timestampTz('telegram_verified_at')->nullable());
        Schema::table('telegram_link_tokens', fn (Blueprint $t) => $t->bigInteger('chat_id')->nullable());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('telegram_verified_at'));
        Schema::table('telegram_link_tokens', fn (Blueprint $t) => $t->dropColumn('chat_id'));
    }
};
