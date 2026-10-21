<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One phone number = one user. No passwords: login is phone + one-time code (Telegram).
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();          // E.164, e.g. +37491234567
            $table->string('name')->nullable();
            $table->string('username', 32)->nullable()->unique();
            $table->string('locale', 2)->default('hy');      // hy | ru | en
            $table->string('avatar_path')->nullable();
            $table->string('level', 16)->nullable();         // beginner | mid | pro
            $table->string('position', 16)->nullable();
            $table->string('district', 32)->nullable();
            $table->string('bio', 300)->nullable();
            $table->bigInteger('telegram_chat_id')->nullable()->unique();
            $table->timestamp('telegram_linked_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('registered_at')->nullable();  // null = created by a guest web booking, no profile yet
            $table->boolean('is_admin')->default(false);
            $table->string('status', 16)->default('active'); // active | blocked
            $table->timestamp('blocked_until')->nullable();
            $table->unsignedSmallInteger('no_show_count')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
