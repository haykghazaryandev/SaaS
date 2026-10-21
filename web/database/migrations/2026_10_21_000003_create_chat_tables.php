<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // direct = two people; game = the chat of an open game (starts when the game opens)
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 8);                       // direct | game
            $table->foreignId('game_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->string('direct_key', 41)->nullable()->unique(); // "<smaller user id>:<bigger user id>"
            $table->string('status', 10)->default('active');  // active | archived (read-only)
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('delete_after')->nullable();    // archived chats are deleted 30 days later
            $table->timestamps();
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('muted_until')->nullable();
            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'left_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null = system message
            $table->string('type', 8)->default('text');      // text | system
            $table->text('body');                             // text, or a translation key for system messages
            $table->jsonb('meta')->nullable();                // system message parameters
            $table->foreignId('reply_to_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->softDeletes();
            $table->timestamp('created_at')->nullable();
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
