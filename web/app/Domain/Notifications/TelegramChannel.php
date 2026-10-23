<?php

namespace App\Domain\Notifications;

use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Notifications\Notification;

/**
 * Laravel notification channel: $notification->toTelegram($user) → message to the user's bot chat.
 * Users without a linked Telegram chat are skipped. Every attempt is written to notification_log.
 */
class TelegramChannel
{
    public function __construct(private TelegramBot $bot) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $chatId = $notifiable->telegram_chat_id ?? null;
        if (! $chatId || ! method_exists($notification, 'toTelegram')) {
            return;
        }
        /** @var TelegramMessage|null $message */
        $message = $notification->toTelegram($notifiable);
        if (! $message) {
            return;
        }
        $log = ['user_id' => $notifiable instanceof User ? $notifiable->id : null, 'channel' => 'telegram', 'chat_id' => $chatId, 'type' => $message->type,
            'payload' => ['text' => $message->text, 'buttons' => $message->buttons]];
        try {
            $res = $this->bot->sendMessage($chatId, $message->text, $message->buttons);
            NotificationLog::create($log + ['status' => ($res['logged'] ?? false) ? 'logged' : 'sent', 'sent_at' => now(),
                'payload' => $log['payload'] + ['message_id' => $res['result']['message_id'] ?? null]]);
        } catch (RequestException $e) {
            $status = $e->response->status();
            NotificationLog::create($log + ['status' => 'failed', 'error' => $status.' '.($e->response->json('description') ?? $e->getMessage())]);
            // 400/403: chat not found or the user blocked the bot, retrying won't help. 429/5xx: let the queue retry with backoff.
            if ($status === 429 || $status >= 500) {
                throw $e;
            }
        }
    }
}
