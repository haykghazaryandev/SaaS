<?php

namespace App\Domain\Notifications\Listeners;

use App\Domain\Booking\Events\BookingChanged;
use App\Domain\Notifications\BookingNotification;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/**
 * BookingChanged → who gets a Telegram message:
 * - the player: requested, accepted (with the code), declined, changed (time/field/price), cancelled by the place or the system, expired
 * - owners and staff of the place: a new request (with Accept / Decline buttons), the player cancelled
 */
class SendBookingTelegram
{
    private const PLAYER = ['requested', 'accepted', 'declined', 'changed', 'cancelled', 'expired'];

    private const PLAYER_CHANGE_KEYS = ['field_id', 'starts_at', 'ends_at', 'price'];

    public function handle(BookingChanged $event): void
    {
        $b = $event->booking->loadMissing('venue', 'field', 'user');
        $type = $event->type;
        $cancelledBy = $event->changes['by'] ?? $b->cancelled_by;

        $toPlayer = in_array($type, self::PLAYER, true)
            && ! ($type === 'cancelled' && $cancelledBy === 'player')
            && ! ($type === 'changed' && ! array_intersect(array_keys($event->changes), self::PLAYER_CHANGE_KEYS));
        if ($toPlayer && $b->user?->telegram_chat_id) {
            $b->user->notify(new BookingNotification($b, $type, 'player', $event->changes));
        }

        $toOwners = $type === 'requested' || ($type === 'cancelled' && $cancelledBy === 'player');
        if ($toOwners) {
            $staff = User::query()
                ->whereIn('id', fn ($q) => $q->select('user_id')->from('venue_users')->where('venue_id', $b->venue_id))
                ->whereNotNull('telegram_chat_id')
                ->when($event->actor, fn ($q) => $q->where('id', '!=', $event->actor->id))
                ->get();
            Notification::send($staff, new BookingNotification($b, $type, 'owner'));
        }
    }
}
