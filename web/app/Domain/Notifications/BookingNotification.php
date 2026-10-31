<?php

namespace App\Domain\Notifications;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A booking update sent to one person by Telegram and / or Web Push (one queued job per channel).
 * $audience: player (the person who booked) | owner (owners and staff of the place).
 * Texts are rendered when the job runs, in the recipient's language (User::preferredLocale).
 */
class BookingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public Booking $booking,
        public string $type,
        public string $audience,
        public array $changes = [],
    ) {}

    /** Telegram allows ~1 msg/s per chat and 30/s overall: retry slowly when it says "too many requests". */
    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    public function via(object $notifiable): array
    {
        // switched off in Profile → Notifications: "My bookings" for players, "Requests for my place" for staff
        $category = $this->audience === 'owner' ? 'requests' : 'bookings';
        $via = [];
        if (($notifiable->telegram_chat_id ?? null) && (! method_exists($notifiable, 'wantsTelegram') || $notifiable->wantsTelegram($category))) {
            $via[] = TelegramChannel::class;
        }
        // not the player's own "request sent": they are in the app, it was their tap
        if (! ($this->audience === 'player' && $this->type === 'requested') && PushNotifications::reaches($notifiable, $category)) {
            $via[] = WebPushChannel::class;
        }

        return $via;
    }

    public function toTelegram(object $notifiable): ?TelegramMessage
    {
        $b = $this->booking->loadMissing('venue', 'field');
        $message = $this->audience === 'owner' ? BookingTexts::owner($b, $this->type) : BookingTexts::player($b, $this->type, $this->changes);
        if (! $notifiable instanceof User) {
            return $message;
        }
        // one more row: open the booking page in the Mini App (logged in by Telegram)
        [$label, $path] = $this->audience === 'owner' ? ['telegram.button.schedule', '/owner'] : ['telegram.button.my_bookings', '/me/bookings'];

        return new TelegramMessage($message->type, $message->text, [...$message->buttons, [[__($label), 'app:'.$path]]]);
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $b = $this->booking->loadMissing('venue', 'field');
        // no phone number on the lock screen (Telegram keeps it)
        $message = $this->audience === 'owner' ? BookingTexts::owner($b, $this->type, false) : BookingTexts::player($b, $this->type, $this->changes);

        return PushNotifications::message($message->text, $this->audience === 'owner' ? '/owner' : '/me/bookings', 'booking:'.$b->id.':'.$this->audience,
            badge: $notifiable instanceof User ? PushNotifications::badgeFor($notifiable) : null);
    }
}
