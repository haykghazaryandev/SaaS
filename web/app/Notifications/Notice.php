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
    public const TELEGRAM = ['slot_free', 'friend_request', 'friend_accepted', 'game_invite', 'game_opened', 'game_joined', 'game_full', 'game_expired',
        'game_booked', 'game_booking_declined', 'game_cancelled', 'game_removed',
        'booking_reminder_day', 'booking_reminder_hour', 'booking_team_invite', 'booking_team_joined', 'booking_team_declined',
        'booking_team_confirmed', 'booking_team_changed', 'booking_team_cancelled',
        'booking_late', 'booking_not_here', 'booking_no_show', 'booking_ban', 'booking_rate',
        // club cards: money matters, so these go to Telegram too
        'card_created', 'card_topup', 'card_paid', 'card_level'];

    /** Categories that always go by Telegram (if switched on), even while the person uses the app: they matter. */
    private const ALWAYS = ['bookings', 'requests', 'alerts'];

    /**
     * $buttons: Telegram button rows under the message, [[label key, callback data, label params], …],
     * translated in the recipient's language when the message is sent, e.g. [[['telegram.button.late', 'late:12:10', ['min' => 10]]]].
     */
    public function __construct(public string $type, public array $params = [], public ?string $link = null, public array $buttons = []) {}

    public function via(object $notifiable): array
    {
        $via = ['database', 'broadcast'];
        $cat = $this->category();
        if (in_array($this->type, self::TELEGRAM, true) && $notifiable->telegram_chat_id
            && (! method_exists($notifiable, 'wantsTelegram') || $notifiable->wantsTelegram($cat))
            // social news (friends, games) only when the app is not open: in the app the bell and the toast show it
            && (in_array($cat, self::ALWAYS, true) || ! method_exists($notifiable, 'isActiveNow') || ! $notifiable->isActiveNow())) {
            $via[] = TelegramChannel::class;
        }

        return $via;
    }

    /** bookings | requests (for the place's staff) | games | friends | alerts */
    public function category(): string
    {
        return match (true) {
            in_array($this->type, ['booking_late', 'booking_not_here'], true) => 'requests',
            str_starts_with($this->type, 'booking_') => 'bookings',
            str_starts_with($this->type, 'game_') => 'games',
            str_starts_with($this->type, 'friend_') => 'friends',
            default => 'alerts',
        };
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
        // a button that opens the page in the Mini App (Bookball inside Telegram); on a local http site the plain address
        if (! str_starts_with((string) config('app.url'), 'https://')) {
            return new TelegramMessage('notice.'.$this->type, $text."\n".rtrim(config('app.url'), '/').$this->link, $rows);
        }
        $url = 'app:'.$this->link;

        return new TelegramMessage('notice.'.$this->type, $text, [...$rows, [[__('telegram.button.open', [], $locale), $url]]]);
    }
}
