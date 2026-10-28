<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Delete chat" for me: hidden from my list (and its messages before that) until somebody writes again. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->after('muted_until');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', fn (Blueprint $table) => $table->dropColumn('hidden_at'));
    }
};
