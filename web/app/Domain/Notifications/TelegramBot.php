<?php

namespace App\Domain\Notifications;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Small client for the Telegram Bot API (@BookballBot).
 * Without TELEGRAM_BOT_TOKEN (development) nothing is sent: the message is written to the log.
 */
class TelegramBot
{
    public function configured(): bool
    {
        return (bool) config('services.telegram.bot_token');
    }

    /** Deep link that opens the bot with /start <token>. */
    public function startLink(string $token): string
    {
        return 'https://t.me/'.config('services.telegram.bot_username').'?start='.$token;
    }

    /** $buttons: rows of [text, callback_data], e.g. [[['✅ Accept', 'accept:12'], ['✖ Decline', 'decline:12']]] */
    public function sendMessage(int|string $chatId, string $text, array $buttons = []): array
    {
        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true] + $this->markup($buttons));
    }

    /** A message with a big "📱 Share my phone number" keyboard button (Telegram sends the user's own contact). */
    public function askContact(int|string $chatId, string $text, string $button): array
    {
        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => [
            'keyboard' => [[['text' => $button, 'request_contact' => true]]], 'resize_keyboard' => true, 'one_time_keyboard' => true,
        ]]);
    }

    /**
     * A message that also removes the reply keyboard (after the contact was shared).
     * $links: [[text, url], …] shown as buttons — only https links (Telegram refuses localhost), otherwise left out.
     */
    public function sendAndHideKeyboard(int|string $chatId, string $text, array $links = []): array
    {
        $links = array_values(array_filter($links, fn ($l) => str_starts_with($l[1], 'https://')));
        $markup = $links ? ['inline_keyboard' => [array_map(fn ($l) => ['text' => $l[0], 'url' => $l[1]], $links)]] : ['remove_keyboard' => true];
        if ($links) {
            // a message can carry only one keyboard: remove the "share phone" keyboard first, quietly
            $this->call('sendMessage', ['chat_id' => $chatId, 'text' => '✅', 'reply_markup' => ['remove_keyboard' => true], 'disable_notification' => true]);
        }

        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true, 'reply_markup' => $markup]);
    }

    /**
     * A message with one row of link buttons (only https links; Telegram refuses localhost) and one row of callback buttons.
     * $links: [[text, url], …]   $actions: [[text, callback_data], …]
     */
    public function sendWithButtons(int|string $chatId, string $text, array $links = [], array $actions = []): array
    {
        $rows = [];
        $links = array_values(array_filter($links, fn ($l) => str_starts_with($l[1], 'https://')));
        if ($links) {
            $rows[] = array_map(fn ($l) => ['text' => $l[0], 'url' => $l[1]], $links);
        }
        if ($actions) {
            $rows[] = array_map(fn ($a) => ['text' => $a[0], 'callback_data' => $a[1]], $actions);
        }

        return $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]
            + ($rows ? ['reply_markup' => ['inline_keyboard' => $rows]] : []));
    }

    public function editMessageText(int|string $chatId, int $messageId, string $text, array $buttons = []): array
    {
        return $this->call('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML']
            + ($buttons ? $this->markup($buttons) : ['reply_markup' => ['inline_keyboard' => []]]));
    }

    public function answerCallbackQuery(string $id, ?string $text = null, bool $alert = false): array
    {
        return $this->call('answerCallbackQuery', array_filter(['callback_query_id' => $id, 'text' => $text, 'show_alert' => $alert ?: null]));
    }

    public function setWebhook(string $url, ?string $secret): array
    {
        return $this->call('setWebhook', array_filter(['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message', 'callback_query'], 'drop_pending_updates' => true]));
    }

    /** Returns the decoded Bot API response; throws RequestException on HTTP errors (so queued jobs retry). */
    public function call(string $method, array $params): array
    {
        if (! $this->configured()) {
            Log::info("[telegram:{$method}] (no bot token, not sent)", $params);

            return ['ok' => true, 'logged' => true];
        }
        $res = Http::asJson()->timeout($method === 'getUpdates' ? 40 : 10)
            ->post('https://api.telegram.org/bot'.config('services.telegram.bot_token').'/'.$method, $params);
        if ($res->failed()) {
            throw new RequestException($res);
        }

        return $res->json() ?? ['ok' => false];
    }

    private function markup(array $buttons): array
    {
        if (! $buttons) {
            return [];
        }
        $rows = array_map(fn ($row) => array_map(fn ($b) => ['text' => $b[0], 'callback_data' => $b[1]], $row), $buttons);

        return ['reply_markup' => ['inline_keyboard' => $rows]];
    }
}
