<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free login: "Log in with Telegram" (our bot asks the user to share their phone; Telegram confirms it)
 * and a backup login by email code. The paid Telegram Gateway code is no longer needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->unique()->after('phone');
            $table->timestamp('email_verified_at')->nullable()->after('email');
            // email-only accounts have no phone until they add it (it is then not confirmed)
            $table->string('phone', 20)->nullable()->change();
        });

        // One "Log in with Telegram" attempt: the browser polls it until the bot approves it.
        Schema::create('telegram_logins', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();         // in the bot deep link: /start login_<token>
            $table->string('status', 10)->default('pending'); // pending | approved | used
            $table->bigInteger('chat_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_logins');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email', 'email_verified_at']);
        });
    }
};
