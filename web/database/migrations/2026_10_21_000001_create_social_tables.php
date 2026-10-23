<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A friendship is one row per pair, whoever asked first. status: pending → accepted
        Schema::create('friendships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();     // who asked
            $table->foreignId('friend_id')->constrained('users')->cascadeOnDelete();   // who was asked
            $table->string('status', 10)->default('pending');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['friend_id', 'status']);
        });
        DB::statement('CREATE UNIQUE INDEX friendships_pair ON friendships (LEAST(user_id, friend_id), GREATEST(user_id, friend_id))');
        DB::statement('ALTER TABLE friendships ADD CONSTRAINT friendships_not_self CHECK (user_id <> friend_id)');

        // Blocked users can't message you, invite you, ask you to be friends or join your games
        Schema::create('user_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['blocker_id', 'blocked_id']);
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('target_type', 10);   // user | message | game
            $table->unsignedBigInteger('target_id');
            $table->string('reason', 500);
            $table->string('status', 10)->default('open'); // open | handled | dismissed
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
        Schema::dropIfExists('user_blocks');
        Schema::dropIfExists('friendships');
    }
};
