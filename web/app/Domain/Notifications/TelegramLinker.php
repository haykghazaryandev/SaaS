<?php

namespace App\Domain\Notifications;

use App\Models\TelegramLinkToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** "Connect Telegram": a one-time token in t.me/<bot>?start=<token> links the bot chat to the user. */
class TelegramLinker
{
    public const VALID_MINUTES = 30;

    public function createToken(User $user): TelegramLinkToken
    {
        TelegramLinkToken::where('user_id', $user->id)->whereNull('used_at')->delete();

        return TelegramLinkToken::create([
            'user_id' => $user->id,
            'token' => Str::random(32),   // Telegram allows [A-Za-z0-9_-], up to 64 chars
            'expires_at' => now()->addMinutes(self::VALID_MINUTES),
        ]);
    }

    /** Returns the linked user, or null when the token is unknown, used or expired. */
    public function link(string $token, int $chatId): ?User
    {
        return DB::transaction(function () use ($token, $chatId) {
            $link = TelegramLinkToken::with('user')->where('token', $token)->whereNull('used_at')
                ->where('expires_at', '>', now())->lockForUpdate()->first();
            if (! $link || ! $link->user) {
                return null;
            }
            // one chat = one account: a chat linked to another account moves to this one
            User::where('telegram_chat_id', $chatId)->where('id', '!=', $link->user_id)
                ->update(['telegram_chat_id' => null, 'telegram_linked_at' => null]);
            $link->user->forceFill(['telegram_chat_id' => $chatId, 'telegram_linked_at' => now()])->save();
            $link->update(['used_at' => now()]);

            return $link->user;
        });
    }
}
