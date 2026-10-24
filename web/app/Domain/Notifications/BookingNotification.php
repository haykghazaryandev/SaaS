<?php

namespace App\Domain\Notifications;

use App\Domain\Auth\LoginLinks;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A booking update sent to one person by Telegram (one queued job per message).
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
        return [TelegramChannel::class];
    }

    public function toTelegram(object $notifiable): ?TelegramMessage
    {
        $b = $this->booking->loadMissing('venue', 'field');
        $message = $this->audience === 'owner' ? BookingTexts::owner($b, $this->type) : BookingTexts::player($b, $this->type, $this->changes);
        if (! $notifiable instanceof User) {
            return $message;
        }
        // one more row: open the booking page, already logged in
        [$label, $path] = $this->audience === 'owner' ? ['telegram.button.schedule', '/owner'] : ['telegram.button.my_bookings', '/me/bookings'];
        $link = app(LoginLinks::class)->make($notifiable, $path, LoginLinks::NOTICE_MINUTES);

        return new TelegramMessage($message->type, $message->text, [...$message->buttons, [[__($label), $link]]]);
    }
}
