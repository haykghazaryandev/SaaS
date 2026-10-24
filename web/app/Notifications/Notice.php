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
    public const TELEGRAM = ['slot_free', 'friend_request', 'game_invite', 'game_opened', 'game_full', 'game_booked', 'game_booking_declined', 'game_cancelled', 'game_removed',
        'booking_reminder_day', 'booking_reminder_hour', 'booking_team_added', 'booking_team_joined',
        'booking_team_confirmed', 'booking_team_changed', 'booking_team_cancelled',
        'booking_late', 'booking_not_here', 'booking_no_show', 'booking_ban', 'booking_rate'];

    /**
     * $buttons: Telegram button rows under the message, [[label key, callback data, label params], …],
     * translated in the recipient's language when the message is sent, e.g. [[['telegram.button.late', 'late:12:10', ['min' => 10]]]].
     */
    public function __construct(public string $type, public array $params = [], public ?string $link = null, public array $buttons = []) {}

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
        $locale = $notifiable->preferredLocale();
        $text = __('notice.'.$this->type, array_map(fn ($v) => e((string) $v), $this->params), $locale);
        $rows = array_map(fn ($row) => array_map(fn ($b) => [__($b[0], $b[2] ?? [], $locale), $b[1]], $row), $this->buttons);
        if (! $this->link) {
            return new TelegramMessage('notice.'.$this->type, $text, $rows);
        }
        // a button that opens the page logged in (https only); on a local http site the plain address instead
        $url = $notifiable instanceof \App\Models\User
            ? app(\App\Domain\Auth\LoginLinks::class)->make($notifiable, $this->link, \App\Domain\Auth\LoginLinks::NOTICE_MINUTES)
            : rtrim(config('app.url'), '/').$this->link;
        if (! str_starts_with($url, 'https://')) {
            return new TelegramMessage('notice.'.$this->type, $text."\n".rtrim(config('app.url'), '/').$this->link, $rows);
        }

        return new TelegramMessage('notice.'.$this->type, $text, [...$rows, [[__('telegram.button.open', [], $locale), $url]]]);
    }
}
