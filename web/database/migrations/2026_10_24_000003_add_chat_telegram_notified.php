<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When the bot last told a member about new messages in a chat (at most one Telegram message per chat every few minutes). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', fn (Blueprint $t) => $t->timestampTz('notified_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('conversation_participants', fn (Blueprint $t) => $t->dropColumn('notified_at'));
    }
};
