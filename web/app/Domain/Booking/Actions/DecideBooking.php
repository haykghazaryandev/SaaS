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

    /** Other requests for the same court and time (several players asked): declined, they hear the time went to another player. */
    public static function declineCompeting(Booking $accepted, User $by, ?self $self = null): int
    {
        $self ??= app(self::class);
        $others = Booking::where('field_id', $accepted->field_id)->where('id', '!=', $accepted->id)->where('status', BookingStatus::Pending)
            ->where('starts_at', '<', $accepted->ends_at)->where('ends_at', '>', $accepted->starts_at)->get();
        foreach ($others as $o) {
            $self->decide($o, $by, BookingStatus::Declined, __('owner.requests.given_to_other'));
        }

        return $others->count();
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
            if ($to === BookingStatus::Accepted) {
                self::declineCompeting($booking, $by, $this);
            }

            return $booking;
        });
    }
}
