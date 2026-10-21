<?php

namespace App\Domain\Notifications\Listeners;

use App\Domain\Booking\Events\BookingChanged;
use App\Domain\Notifications\BookingNotification;
use App\Models\User;
use App\Notifications\Notice;
use App\Support\LocalTime;
use Illuminate\Support\Facades\Notification;

/**
 * BookingChanged → who is told, by Telegram and in the app (bell + live toast):
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
        // in the app: the answer of the place (not the player's own "request sent")
        if ($toPlayer && $type !== 'requested' && $b->user) {
            $t = $b->starts_at->toImmutable()->setTimezone(LocalTime::tz());
            $b->user->notify(new Notice('booking_'.$type, [
                'venue' => $b->venue->name, 'field' => $b->field->format, 'date' => $t->format('d.m'),
                'time' => $t->format('H:i').'–'.$b->ends_at->toImmutable()->setTimezone(LocalTime::tz())->format('H:i'),
                'code' => $b->code, 'reason' => $b->decline_reason ?? $b->cancel_reason ?? '',
            ], '/me/bookings'));
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
