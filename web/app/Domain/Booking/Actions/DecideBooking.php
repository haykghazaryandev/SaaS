<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\BookingException;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;

/** The place accepts or declines a pending request. */
class DecideBooking
{
    use BookingWriter;

    public function accept(Booking $booking, User $by): Booking
    {
        return $this->decide($booking, $by, BookingStatus::Accepted);
    }

    public function decline(Booking $booking, User $by, ?string $reason = null): Booking
    {
        return $this->decide($booking, $by, BookingStatus::Declined, $reason);
    }

    private function decide(Booking $booking, User $by, BookingStatus $to, ?string $reason = null): Booking
    {
        return $this->guarded(function () use ($booking, $by, $to, $reason) {
            $booking = Booking::lockForUpdate()->findOrFail($booking->id);
            if ($booking->status !== BookingStatus::Pending) {
                throw new BookingException('not_pending');
            }
            $booking->update(['status' => $to, 'decided_by' => $by->id, 'decided_at' => now(), 'decline_reason' => $to === BookingStatus::Declined ? $reason : null]);
            $this->log($booking, $to === BookingStatus::Accepted ? 'accepted' : 'declined', $by, $reason ? ['reason' => $reason] : []);

            return $booking;
        });
    }
}
