<?php

namespace App\Domain\Notifications;

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
            $this->bot->sendWithButtons($chatId, __('telegram.login.done', ['name' => $known?->name ? ', '.e($known->name) : ''], $locale), $this->appLinks($locale), $this->langButton($locale));
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
        $ok ? $this->bot->sendWithButtons($chatId, $text, $this->appLinks($locale), $this->langButton($locale))
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

    /** Buttons that open Bookball: book a field, find players. */
    private function appLinks(string $locale): array
    {
        $url = rtrim(config('app.url'), '/');

        return [[__('telegram.login.btnBook', [], $locale), $url.'/?lang='.$locale], [__('telegram.login.btnGames', [], $locale), $url.'/games?lang='.$locale]];
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
