<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Notify me if it frees up": a player waits for a booked time of a field. When that time becomes free
 * (cancelled, declined, expired or moved), everybody waiting is told once (Telegram + in-app); first to book gets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slot_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('starts_at');
            $table->unsignedSmallInteger('minutes');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'field_id', 'starts_at']);
            $table->index(['field_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slot_alerts');
    }
};
