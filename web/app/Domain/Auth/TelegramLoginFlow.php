<?php

namespace App\Domain\Auth;

use App\Domain\Notifications\TelegramBot;
use App\Models\TelegramLogin;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Free "Log in with Telegram":
 * 1. the browser starts a login → deep link t.me/<bot>?start=login_<token>; it then polls the status
 * 2. the bot receives /start login_<token>:
 *    - the chat already belongs to an account → approved at once (one tap)
 *    - otherwise the bot shows a "📱 Share my phone number" button
 * 3. the user shares their contact; Telegram sends the phone number of their OWN account
 *    (we check contact.user_id == from.id), so the phone is confirmed — no paid code needed
 * 4. the account is found or created by that phone (registered at once with the Telegram name), the chat is linked, the login is approved
 * 5. the browser that started it (the token is kept in its session) completes the login.
 */
class TelegramLoginFlow
{
    public const MINUTES = 10;

    public function __construct(private TelegramBot $bot) {}

    public function start(): TelegramLogin
    {
        TelegramLogin::where('expires_at', '<', now()->subDay())->delete();

        return TelegramLogin::create(['token' => Str::random(40), 'expires_at' => now()->addMinutes(self::MINUTES)]);
    }

    public function deepLink(TelegramLogin $login): string
    {
        return $this->bot->startLink('login_'.$login->token);
    }

    /** Bot: /start login_<token>. Returns 'approved', 'ask_contact' or 'expired'. */
    public function onStart(string $token, int $chatId, array $from): string
    {
        $login = TelegramLogin::where('token', $token)->where('status', 'pending')->first();
        if (! $login || $login->isExpired()) {
            return 'expired';
        }
        $login->update(['chat_id' => $chatId]);
        $user = User::where('telegram_chat_id', $chatId)->whereNotNull('phone_verified_at')->first();
        if ($user && ! $user->isBlocked()) {
            $this->approve($login, $user);

            return 'approved';
        }

        return 'ask_contact';
    }

    /**
     * Bot: the user shared a contact. Returns [result, user|null]:
     * result: approved | linked (no login waiting, the chat was just linked) | not_own | bad_phone | blocked
     */
    public function onContact(int $chatId, array $from, array $contact): array
    {
        if ((int) ($contact['user_id'] ?? 0) !== (int) ($from['id'] ?? -1)) {
            return ['not_own', null];   // someone else's contact: not a confirmation
        }
        $phone = Phone::normalize($contact['phone_number'] ?? '');
        if (! $phone) {
            return ['bad_phone', null];
        }
        $name = trim(($contact['first_name'] ?? $from['first_name'] ?? '').' '.($contact['last_name'] ?? $from['last_name'] ?? '')) ?: null;
        $user = $this->confirmedUser($phone, $name, $from['language_code'] ?? null);
        if ($user->isBlocked()) {
            return ['blocked', $user];
        }
        $this->linkChat($user, $chatId);
        $login = TelegramLogin::where('chat_id', $chatId)->where('status', 'pending')->where('expires_at', '>', now())->latest('id')->first();
        if ($login) {
            $this->approve($login, $user);

            return ['approved', $user];
        }

        return ['linked', $user];
    }

    /** Development only (no bot token): approve a login as if this phone was shared in Telegram. */
    public function simulate(TelegramLogin $login, string $phone, ?string $name = null): User
    {
        $user = $this->confirmedUser($phone, $name, null);
        $this->approve($login, $user);

        return $user;
    }

    /** pending | approved | expired | used */
    public function status(TelegramLogin $login): string
    {
        if ($login->status === 'pending' && $login->isExpired()) {
            return 'expired';
        }

        return $login->status;
    }

    /** The browser takes the approved login (once). */
    public function complete(TelegramLogin $login): ?User
    {
        return DB::transaction(function () use ($login) {
            $login = TelegramLogin::with('user')->lockForUpdate()->find($login->id);
            if (! $login || $login->status !== 'approved' || ! $login->user || $login->approved_at->lt(now()->subMinutes(self::MINUTES))) {
                return null;
            }
            $login->update(['status' => 'used']);

            return $login->user;
        });
    }

    /**
     * The account of a confirmed phone. An account that only CLAIMED this phone (email signup, not confirmed)
     * loses it: the real owner of the number gets their own account.
     */
    private function confirmedUser(string $phone, ?string $name, ?string $lang): User
    {
        return DB::transaction(function () use ($phone, $name, $lang) {
            $user = User::withTrashed()->where('phone', $phone)->lockForUpdate()->first();
            if ($user && ! $user->phone_verified_at) {
                $user->forceFill(['phone' => null])->save();
                $user = null;
            }
            if (! $user) {
                $locale = in_array(substr((string) $lang, 0, 2), config('app.locales'), true) ? substr($lang, 0, 2) : app()->getLocale();
                $user = User::create(['phone' => $phone, 'name' => $name, 'locale' => $locale]);
            }
            if ($user->trashed()) {
                $user->restore();
            }
            // the Telegram name is enough to register: no extra "welcome" form (name and username can be changed in the profile)
            $name = $user->name ?: $name;
            $user->forceFill([
                'phone_verified_at' => $user->phone_verified_at ?? now(), 'name' => $name, 'last_seen_at' => now(),
                'registered_at' => $user->registered_at ?? ($name ? now() : null),
            ])->save();
            $user->syncAdmin();

            return $user;
        });
    }

    private function linkChat(User $user, int $chatId): void
    {
        User::where('telegram_chat_id', $chatId)->where('id', '!=', $user->id)->update(['telegram_chat_id' => null, 'telegram_linked_at' => null]);
        $user->forceFill(['telegram_chat_id' => $chatId, 'telegram_linked_at' => $user->telegram_linked_at ?? now()])->save();
    }

    private function approve(TelegramLogin $login, User $user): void
    {
        $login->update(['status' => 'approved', 'user_id' => $user->id, 'approved_at' => now()]);
    }
}
