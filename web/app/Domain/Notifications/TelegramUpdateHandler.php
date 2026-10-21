<?php

namespace App\Domain\Notifications;

use App\Domain\Booking\Actions\DecideBooking;
use App\Domain\Booking\BookingException;
use App\Models\Booking;
use App\Models\User;

/**
 * Handles updates from the Telegram webhook:
 * - "/start <token>": links the chat to the account (Connect Telegram)
 * - inline buttons "accept:<id>" / "decline:<id>" on new-request messages for owners and staff
 */
class TelegramUpdateHandler
{
    public function __construct(private TelegramBot $bot, private TelegramLinker $linker, private DecideBooking $decide) {}

    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->callback($update['callback_query']);
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
        if (! str_starts_with($text, '/start')) {
            $user = User::where('telegram_chat_id', $chatId)->first();
            $this->reply($chatId, 'telegram.bot.help', $user?->preferredLocale() ?? $this->locale($m['from'] ?? []));

            return;
        }
        $token = trim(substr($text, 6));
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

    private function callback(array $q): void
    {
        $fromId = (int) ($q['from']['id'] ?? 0);
        $chatId = (int) ($q['message']['chat']['id'] ?? $fromId);
        $messageId = (int) ($q['message']['message_id'] ?? 0);
        $user = User::with('venues')->where('telegram_chat_id', $fromId)->first()
            ?? User::with('venues')->where('telegram_chat_id', $chatId)->first();
        $locale = $user?->preferredLocale() ?? $this->locale($q['from'] ?? []);

        app()->setLocale($locale);
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
