<?php

namespace App\Domain\Booking\AfterBooking;

use App\Domain\Booking\Actions\BookingWriter;
use App\Domain\Booking\BookingException;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;

/**
 * At the field: the place marks the team as arrived (optional) or as not arrived (no-show);
 * the player can say "we're about N minutes late". Events: arrived | late | no_show (BookingChanged).
 *
 * A late team still ends at the booked time and pays the full price (the next team is waiting).
 */
class Attendance
{
    use BookingWriter;

    public const LATE_OPTIONS = [5, 10, 15, 20, 30];

    /** The place: "the team is here". From 30 minutes before the start until the no-show deadline. */
    public function arrived(User $by, Booking $booking): Booking
    {
        return $this->guarded(function () use ($by, $booking) {
            $b = Booking::lockForUpdate()->findOrFail($booking->id);
            if (! in_array($b->status, [BookingStatus::Accepted, BookingStatus::Completed], true)
                || $b->starts_at->greaterThan(now()->addMinutes(30)) || now()->greaterThan(BookingPeople::noShowDeadline($b))) {
                throw new BookingException('not_active');
            }
            if (! $b->arrived_at) {
                $b->update(['arrived_at' => now()]);
                $this->log($b, 'arrived', $by);
            }

            return $b;
        });
    }

    /** The player (booker or team): "about $minutes late". From 3 h before until the end of the grace time. */
    public function late(User $player, Booking $booking, int $minutes): Booking
    {
        if (! in_array($minutes, self::LATE_OPTIONS, true)) {
            throw new BookingException('not_active');
        }

        return $this->guarded(function () use ($player, $booking, $minutes) {
            $b = Booking::with('venue')->lockForUpdate()->findOrFail($booking->id);
            if (! self::canSayLate($b) || ! $b->attendees()->contains('id', $player->id)) {
                throw new BookingException('not_active');
            }
            $b->update(['late_minutes' => $minutes, 'late_at' => now()]);
            $this->log($b, 'late', $player, ['minutes' => $minutes]);

            return $b;
        });
    }

    /** The place: "the team did not come". After the start, until 12:00 the next day; not after "arrived". */
    public function noShow(User $by, Booking $booking): Booking
    {
        return $this->guarded(function () use ($by, $booking) {
            $b = Booking::with('user')->lockForUpdate()->findOrFail($booking->id);
            if (! self::canNoShow($b)) {
                throw new BookingException('not_active');
            }
            $b->update(['status' => BookingStatus::NoShow]);
            $b->user?->increment('no_show_count');
            $this->log($b, 'no_show', $by);

            return $b;
        });
    }

    public static function canSayLate(Booking $b): bool
    {
        $b->loadMissing('venue');

        return $b->status === BookingStatus::Accepted && ! $b->arrived_at
            && now()->greaterThanOrEqualTo($b->starts_at->copy()->subHours(3))
            && now()->lessThan($b->starts_at->copy()->addMinutes((int) $b->venue->rule('late_grace_min')));
    }

    public static function canArrive(Booking $b): bool
    {
        return ! $b->arrived_at && in_array($b->status, [BookingStatus::Accepted, BookingStatus::Completed], true)
            && $b->starts_at->lessThanOrEqualTo(now()->addMinutes(30)) && now()->lessThanOrEqualTo(BookingPeople::noShowDeadline($b));
    }

    public static function canNoShow(Booking $b): bool
    {
        return ! $b->arrived_at && in_array($b->status, [BookingStatus::Accepted, BookingStatus::Completed], true)
            && $b->starts_at->isPast() && now()->lessThanOrEqualTo(BookingPeople::noShowDeadline($b));
    }
}
