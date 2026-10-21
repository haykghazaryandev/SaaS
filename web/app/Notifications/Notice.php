<?php

namespace App\Notifications;

use App\Domain\Notifications\TelegramChannel;
use App\Domain\Notifications\TelegramMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * A social / game notification: saved for the in-app bell (database), pushed live (Reverb broadcast)
 * and, for the important types, sent by Telegram.
 *
 * $type examples: friend_request, friend_accepted, game_invite, game_opened, game_joined, game_full,
 * game_left, game_removed, game_cancelled, game_booking_requested, game_booked, game_booking_declined.
 * Texts: lang/{en,hy,ru}/notice.php → notice.<type>, with $params as :placeholders.
 * $link is the page to open (relative URL).
 */
class Notice extends Notification implements ShouldQueue
{
    use Queueable;

    /** Types that also go to Telegram (people connected the bot to hear about these). */
    public const TELEGRAM = ['friend_request', 'game_invite', 'game_opened', 'game_full', 'game_booked', 'game_booking_declined', 'game_cancelled', 'game_removed'];

    public function __construct(public string $type, public array $params = [], public ?string $link = null) {}

    public function via(object $notifiable): array
    {
        $via = ['database', 'broadcast'];
        if (in_array($this->type, self::TELEGRAM, true) && $notifiable->telegram_chat_id) {
            $via[] = TelegramChannel::class;
        }

        return $via;
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => $this->type, 'params' => $this->params, 'link' => $this->link];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'type' => $this->type,
            'params' => $this->params,
            'link' => $this->link,
            'text' => __('notice.'.$this->type, $this->params, $notifiable->preferredLocale()),
        ]);
    }

    public function toTelegram(object $notifiable): ?TelegramMessage
    {
        $text = __('notice.'.$this->type, array_map(fn ($v) => e((string) $v), $this->params), $notifiable->preferredLocale());
        if ($this->link) {
            $text .= "\n".rtrim(config('app.url'), '/').$this->link;
        }

        return new TelegramMessage('notice.'.$this->type, $text);
    }
}
