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
        $res = Http::asJson()->timeout(10)
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
