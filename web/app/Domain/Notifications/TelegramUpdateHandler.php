<?php

namespace App\Domain\Notifications;

use App\Domain\Booking\AfterBooking;
use App\Domain\Auth\TelegramLoginFlow;
use App\Domain\Booking\Actions\DecideBooking;
use App\Domain\Booking\BookingException;
use App\Models\Booking;
use App\Models\User;

/**
 * Handles updates from the Telegram webhook:
 * - "/start login_<token>" + a shared contact: free "Log in with Telegram" (see TelegramLoginFlow)
 * - "/start <token>": links the chat to the account (Connect Telegram)
 * - inline buttons "accept:<id>" / "decline:<id>" on new-request messages for owners and staff
 * - "/language" and the "🌐 Language" button: buttons "lang:hy|ru|en" set the user's language (bot + website)
 */
class TelegramUpdateHandler
{
    public function __construct(private TelegramBot $bot, private TelegramLinker $linker, private DecideBooking $decide, private TelegramLoginFlow $login) {}

    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->callback($update['callback_query']);
        } elseif (isset($update['message']['contact'])) {
            $this->contact($update['message']);
        } elseif (isset($update['message']['text'])) {
            $this->message($update['message']);
        }
    }

    private function message(array $m): void
    {
        $chatId = (int) ($m['chat']['id'] ?? 0);
        if (! $chatId || ($m['chat']['type'] ?? 'private') !== 'private') {
            return;
        }
        $text = trim($m['text']);
        if (str_starts_with($text, '/language')) {
            $this->askLanguage($chatId, $m['from'] ?? []);

            return;
        }
        if (! str_starts_with($text, '/start')) {
            $user = User::where('telegram_chat_id', $chatId)->first();
            $this->reply($chatId, 'telegram.bot.help', $user?->preferredLocale() ?? $this->locale($m['from'] ?? []));

            return;
        }
        $token = trim(substr($text, 6));
        if (str_starts_with($token, 'login_')) {
            $this->startLogin(substr($token, 6), $chatId, $m['from'] ?? []);

            return;
        }
        if ($token === '') {
            $user = User::where('telegram_chat_id', $chatId)->first();
            $this->reply($chatId, $user ? 'telegram.bot.already' : 'telegram.bot.hello', $user?->preferredLocale() ?? $this->locale($m['from'] ?? []));

            return;
        }
        $user = $this->linker->link($token, $chatId);
        if (! $user) {
            $this->reply($chatId, 'telegram.bot.link_expired', $this->locale($m['from'] ?? []));

            return;
        }
        $this->reply($chatId, 'telegram.bot.linked', $user->preferredLocale(), ['name' => $user->name ? ', '.e($user->name) : '']);
    }

    /** "Log in with Telegram": approve at once if we know this chat, otherwise ask for the phone number. */
    private function startLogin(string $token, int $chatId, array $from): void
    {
        $known = User::where('telegram_chat_id', $chatId)->first();
        $locale = $known?->preferredLocale() ?? $this->locale($from);
        $result = $this->login->onStart($token, $chatId, $from);
        if ($result === 'expired') {
            $this->reply($chatId, 'telegram.login.expired', $locale);
        } elseif ($result === 'approved') {
            $this->bot->sendWithButtons($chatId, __('telegram.login.done', ['name' => $known?->name ? ', '.e($known->name) : ''], $locale), $this->appLinks($locale, $known), $this->langButton($locale));
        } else {
            $this->bot->askContact($chatId, __('telegram.login.ask', [], $locale), __('telegram.login.button', [], $locale));
        }
    }

    /** The user shared a contact (the "Share my phone number" button). */
    private function contact(array $m): void
    {
        $chatId = (int) ($m['chat']['id'] ?? 0);
        if (! $chatId || ($m['chat']['type'] ?? 'private') !== 'private') {
            return;
        }
        [$result, $user] = $this->login->onContact($chatId, $m['from'] ?? [], $m['contact']);
        $locale = $user?->preferredLocale() ?? $this->locale($m['from'] ?? []);
        $key = ['approved' => 'telegram.login.done', 'linked' => 'telegram.login.linked'][$result] ?? 'telegram.login.'.$result;
        $ok = in_array($result, ['approved', 'linked'], true);
        $text = __($key, ['name' => $user?->name ? ', '.e($user->name) : '', 'url' => rtrim(config('app.url'), '/')], $locale);
        $ok ? $this->bot->sendWithButtons($chatId, $text, $this->appLinks($locale, $user), $this->langButton($locale))
            : $this->bot->sendAndHideKeyboard($chatId, $text);
    }

    /** "/language": three buttons; the choice is saved on the account (bot + website). */
    private function askLanguage(int $chatId, array $from): void
    {
        $user = User::where('telegram_chat_id', $chatId)->first();
        $locale = $user?->preferredLocale() ?? $this->locale($from);
        $this->bot->sendMessage($chatId, __('telegram.lang.choose', [], $locale), [self::LANG_BUTTONS]);
    }

    /** The "🌐 Language: Հայերեն" button under login messages. */
    private function langButton(string $locale): array
    {
        $names = ['hy' => 'Հայերեն', 'ru' => 'Русский', 'en' => 'English'];

        return [['🌐 '.__('telegram.lang.button', [], $locale).': '.$names[$locale], 'lang:menu']];
    }

    private const LANG_BUTTONS = [['🇦🇲 Հայերեն', 'lang:hy'], ['🇷🇺 Русский', 'lang:ru'], ['🇬🇧 English', 'lang:en']];

    private function setLanguage(array $q, ?User $user, string $lang, int $chatId, int $messageId): void
    {
        if (! $user) {
            $this->bot->answerCallbackQuery($q['id'], __('telegram.lang.need_login', [], $lang), true);

            return;
        }
        $user->forceFill(['locale' => $lang])->save();
        $this->bot->answerCallbackQuery($q['id'], __('telegram.lang.short', [], $lang));
        if ($messageId) {
            $this->bot->editMessageText($chatId, $messageId, __('telegram.lang.set', [], $lang));
        }
    }

    /** Buttons that open Bookball inside Telegram (the Mini App logs the person in by itself): book a field, find players. */
    private function appLinks(string $locale, ?User $user = null): array
    {
        return [[__('telegram.login.btnBook', [], $locale), 'app:/?lang='.$locale], [__('telegram.login.btnGames', [], $locale), 'app:/games?lang='.$locale]];
    }

    private function callback(array $q): void
    {
        $fromId = (int) ($q['from']['id'] ?? 0);
        $chatId = (int) ($q['message']['chat']['id'] ?? $fromId);
        $messageId = (int) ($q['message']['message_id'] ?? 0);
        $user = User::with('venues')->where('telegram_chat_id', $fromId)->first()
            ?? User::with('venues')->where('telegram_chat_id', $chatId)->first();
        $locale = $user?->preferredLocale() ?? $this->locale($q['from'] ?? []);

        app()->setLocale($locale);
        if (($q['data'] ?? '') === 'lang:menu') {
            $this->bot->answerCallbackQuery($q['id']);
            $this->bot->sendMessage($chatId, __('telegram.lang.choose', [], $locale), [self::LANG_BUTTONS]);

            return;
        }
        if (preg_match('/^lang:(hy|ru|en)$/', (string) ($q['data'] ?? ''), $lm)) {
            $this->setLanguage($q, $user, $lm[1], $chatId, $messageId);

            return;
        }
        if ($this->afterBooking($q, $user, $chatId, $messageId)) {
            return;
        }
        if (! preg_match('/^(accept|decline):(\d+)$/', (string) ($q['data'] ?? ''), $mm)) {
            $this->bot->answerCallbackQuery($q['id']);

            return;
        }
        $booking = Booking::with('venue', 'field')->find((int) $mm[2]);
        if (! $user || ! $booking || ! $user->worksAt($booking->venue_id) || $user->isBlocked()) {
            $this->bot->answerCallbackQuery($q['id'], __('telegram.bot.not_allowed'), true);

            return;
        }
        try {
            $booking = $mm[1] === 'accept' ? $this->decide->accept($booking, $user) : $this->decide->decline($booking, $user);
            $this->bot->answerCallbackQuery($q['id'], __('telegram.status.'.$booking->status->value));
        } catch (BookingException $e) {
            $booking->refresh();
            $this->bot->answerCallbackQuery($q['id'], __('booking.error.'.$e->reason), true);
        }
        if ($messageId) {
            $booking->loadMissing('venue', 'field');
            $by = $booking->decided_by ? (User::find($booking->decided_by) ?? $user) : $user;
            $this->bot->editMessageText($chatId, $messageId, BookingTexts::ownerDecided($booking, $by));
        }
    }

    /**
     * Buttons of the after-booking messages (AfterBooking\Reminders):
     * the player: late:{id}:{min}, cantcome:{id} → cancelyes:{id}, rate:{id}:{stars};
     * the place: arrived:{id}, noshow:{id}, wait:{id}.
     */
    private function afterBooking(array $q, ?User $user, int $chatId, int $messageId): bool
    {
        // an invite to a friend's booking: teamyes:{booking_players.id} / teamno:{id}
        if (preg_match('/^team(yes|no):(\d+)$/', (string) ($q['data'] ?? ''), $t)) {
            $row = \App\Models\BookingPlayer::with('booking.venue', 'booking.field', 'booking.user')->find((int) $t[2]);
            if (! $user || ! $row || $row->user_id !== $user->id) {
                $this->bot->answerCallbackQuery($q['id'], __('telegram.bot.not_allowed'), true);

                return true;
            }
            try {
                app(AfterBooking\BookingTeam::class)->respond($row, $user, $t[1] === 'yes');
                $key = $t[1] === 'yes' ? 'telegram.after.team_in' : 'telegram.after.team_out';
                if ($messageId) {
                    $this->bot->editMessageText($chatId, $messageId, __($key, $this->esc(AfterBooking\BookingPeople::params($row->booking))));
                }
                $this->bot->answerCallbackQuery($q['id'], __($key.'_short'));
            } catch (BookingException $e) {
                $this->bot->answerCallbackQuery($q['id'], __('booking.error.'.$e->reason), true);
            }

            return true;
        }
        if (! preg_match('/^(late|cantcome|cancelyes|rate|arrived|noshow|wait):(\d+)(?::(\d+))?$/', (string) ($q['data'] ?? ''), $m)) {
            return false;
        }
        [, $action, $id] = $m;
        $arg = (int) ($m[3] ?? 0);
        $booking = Booking::with('venue', 'field', 'user', 'players')->find((int) $id);
        $staff = in_array($action, ['arrived', 'noshow', 'wait'], true);
        $allowed = $user && $booking && ! $user->isBlocked()
            && ($staff ? $user->worksAt($booking->venue_id) : ($action === 'late' || $action === 'rate' ? $booking->attendees()->contains('id', $user->id) : $booking->user_id === $user->id));
        if (! $allowed) {
            $this->bot->answerCallbackQuery($q['id'], __('telegram.bot.not_allowed'), true);

            return true;
        }
        try {
            $answer = match ($action) {
                'late' => $this->replyLate($booking, $user, $arg),
                'cantcome' => $this->askCancel($booking, $chatId),
                'cancelyes' => $this->cancelFromBot($booking, $user, $chatId, $messageId),
                'rate' => $this->rateFromBot($booking, $user, $arg, $chatId, $messageId),
                'arrived' => $this->staffAnswer($chatId, $messageId, app(AfterBooking\Attendance::class)->arrived($user, $booking), 'telegram.after.arrived_done', $user),
                'noshow' => $this->staffAnswer($chatId, $messageId, app(AfterBooking\Attendance::class)->noShow($user, $booking), 'telegram.after.no_show_done', $user),
                'wait' => __('telegram.after.waiting'),
            };
            $this->bot->answerCallbackQuery($q['id'], $answer);
        } catch (BookingException $e) {
            $this->bot->answerCallbackQuery($q['id'], __('booking.error.'.$e->reason), true);
        }

        return true;
    }

    private function replyLate(Booking $b, User $user, int $minutes): string
    {
        app(AfterBooking\Attendance::class)->late($user, $b, $minutes);

        return __('telegram.after.late_sent', ['min' => $minutes]);
    }

    private function askCancel(Booking $b, int $chatId): string
    {
        $text = __('telegram.after.cancel_ask', $this->esc(AfterBooking\BookingPeople::params($b)));
        $free = $b->starts_at->greaterThan(now()->addHours((int) $b->venue->rule('free_cancel_hours')));
        $this->bot->sendMessage($chatId, $text.($free ? '' : "\n\n".__('telegram.after.cancel_late')), [[[__('telegram.after.cancel_yes'), "cancelyes:{$b->id}"]]]);

        return '';
    }

    private function cancelFromBot(Booking $b, User $user, int $chatId, int $messageId): string
    {
        app(\App\Domain\Booking\Actions\CancelBooking::class)->handle($b, $user, 'player');
        if ($messageId) {
            $this->bot->editMessageText($chatId, $messageId, __('telegram.after.cancelled', $this->esc(AfterBooking\BookingPeople::params($b))));
        }

        return __('telegram.status.cancelled');
    }

    private function rateFromBot(Booking $b, User $user, int $stars, int $chatId, int $messageId): string
    {
        app(AfterBooking\Reviews::class)->rate($b, $user, $stars);
        if ($messageId) {
            $this->bot->editMessageText($chatId, $messageId, __('telegram.after.rated', ['stars' => str_repeat('⭐', $stars)] + $this->esc(AfterBooking\BookingPeople::params($b))));
        }

        return __('telegram.after.thanks');
    }

    /** Message texts are HTML: escape names and places. */
    private function esc(array $params): array
    {
        return array_map(fn ($v) => e((string) $v), $params);
    }

    /** The place answered "team not here?": replace the buttons with who answered what. */
    private function staffAnswer(int $chatId, int $messageId, Booking $b, string $key, User $by): string
    {
        if ($messageId) {
            $this->bot->editMessageText($chatId, $messageId, __($key, ['by' => e($by->name)] + $this->esc(AfterBooking\BookingPeople::params($b))));
        }

        return __($key.'_short');
    }

    private function reply(int $chatId, string $key, string $locale, array $replace = []): void
    {
        $this->bot->sendMessage($chatId, __($key, $replace + ['bot' => config('services.telegram.bot_username'), 'url' => rtrim(config('app.url'), '/')], $locale));
    }

    /** Language of a Telegram user we don't know yet (their app language). */
    private function locale(array $from): string
    {
        $code = substr((string) ($from['language_code'] ?? ''), 0, 2);

        return in_array($code, config('app.locales'), true) ? $code : 'hy';
    }
}
