<?php

namespace App\Domain\Notifications;

use App\Models\TelegramLinkToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Connect Telegram": a one-time token in t.me/<bot>?start=<token>; the bot then asks for the phone, and the chat is
 * linked only when the Telegram account's own number is the Bookball account's number (see finish()).
 */
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

    /**
     * Step 1, the bot got /start <token>: remember the chat (nothing is linked yet; the bot then asks for the phone).
     * Returns the account that asked, or null when the token is unknown, used or expired.
     */
    public function start(string $token, int $chatId): ?User
    {
        $link = TelegramLinkToken::with('user')->where('token', $token)->whereNull('used_at')->where('expires_at', '>', now())->first();
        if (! $link || ! $link->user) {
            return null;
        }
        $link->update(['chat_id' => $chatId]);

        return $link->user;
    }

    /**
     * Step 2, the person shared their own Telegram contact: link only when that number is the account's number.
     * Returns [result, user]: result linked | mismatch | none (no "Connect Telegram" waiting for this chat).
     */
    public function finish(int $chatId, string $phone): array
    {
        return DB::transaction(function () use ($chatId, $phone) {
            $link = TelegramLinkToken::with('user')->where('chat_id', $chatId)->whereNull('used_at')
                ->where('expires_at', '>', now())->latest('id')->lockForUpdate()->first();
            if (! $link || ! $link->user) {
                return ['none', null];
            }
            $link->update(['used_at' => now()]);
            if ($link->user->phone !== $phone) {
                return ['mismatch', $link->user];   // someone else's Telegram: never attach it to this account
            }
            // one chat = one account: a chat linked to another account moves to its real owner
            User::where('telegram_chat_id', $chatId)->where('id', '!=', $link->user_id)
                ->update(['telegram_chat_id' => null, 'telegram_linked_at' => null, 'telegram_verified_at' => null]);
            $link->user->forceFill(['telegram_chat_id' => $chatId, 'telegram_linked_at' => now(), 'telegram_verified_at' => now()])->save();

            return ['linked', $link->user];
        });
    }
}
