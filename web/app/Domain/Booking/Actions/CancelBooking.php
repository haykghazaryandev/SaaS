<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\BookingException;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;

/** Cancel a pending or accepted booking: by the player ($by = player), the place ($by = owner) or the system. */
class CancelBooking
{
    use BookingWriter;

    public function handle(Booking $booking, ?User $actor, string $by, ?string $reason = null): Booking
    {
        return $this->guarded(function () use ($booking, $actor, $by, $reason) {
            $booking = Booking::with('venue')->lockForUpdate()->findOrFail($booking->id);
            if (! $booking->status->holdsTime()) {
                throw new BookingException('not_active');
            }
            if ($booking->starts_at->isPast()) {
                throw new BookingException('past');
            }
            $late = $by === 'player' && $booking->status === BookingStatus::Accepted
                && $booking->starts_at->lessThan(now()->addHours($booking->venue->rule('free_cancel_hours')));
            $booking->update(['status' => BookingStatus::Cancelled, 'cancelled_by' => $by, 'cancel_reason' => $reason]);
            $this->log($booking, 'cancelled', $actor, array_filter(['by' => $by, 'reason' => $reason, 'late' => $late ?: null]));

            return $booking;
        });
    }
}
