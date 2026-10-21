<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every Telegram message we try to send (shown in the admin panel)
        Schema::create('notification_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 16)->default('telegram');  // telegram | push (later)
            $table->bigInteger('chat_id')->nullable();
            $table->string('type', 48)->index();                 // booking.accepted, venue.requested …
            $table->jsonb('payload')->nullable();
            $table->string('status', 12);                        // sent | logged | failed
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_log');
    }
};
