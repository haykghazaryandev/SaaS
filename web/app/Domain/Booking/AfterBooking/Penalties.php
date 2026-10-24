<?php

namespace App\Domain\Booking\AfterBooking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\User;
use App\Notifications\Notice;
use App\Support\LocalTime;

/**
 * No-shows have a consequence (product decision 2026-10-24):
 * - every no-show is counted; a cancel less than 2 h before the start counts as half a no-show;
 * - 3 points within 60 days: no new booking requests for 30 days (the player is told why);
 * - the admin can reset it (Users > Reset no-shows): older points then no longer count.
 */
class Penalties
{
    public const WINDOW_DAYS = 60;

    public const LIMIT = 3;

    public const BAN_DAYS = 30;

    public const SHORT_CANCEL_HOURS = 2;

    public function points(User $user): float
    {
        $since = now()->subDays(self::WINDOW_DAYS);
        if ($user->penalty_reset_at && $user->penalty_reset_at->greaterThan($since)) {
            $since = $user->penalty_reset_at;
        }
        // counted when the place marked it (still a no-show now)
        $noShows = BookingEvent::where('type', 'no_show')->where('created_at', '>=', $since)
            ->whereIn('booking_id', Booking::select('id')->where('user_id', $user->id)->where('status', BookingStatus::NoShow))->count();
        $short = BookingEvent::where('type', 'cancelled')->where('created_at', '>=', $since)->where('changes->short', true)
            ->whereIn('booking_id', Booking::select('id')->where('user_id', $user->id))->count();

        return $noShows + $short / 2;
    }

    /** After a no-show or a very late cancel: ban when the limit is reached. Returns true when banned now. */
    public function check(User $user): bool
    {
        if ($user->isBookingBanned() || $this->points($user) < self::LIMIT) {
            return false;
        }
        $until = now()->addDays(self::BAN_DAYS);
        $user->forceFill(['booking_ban_until' => $until])->save();
        $user->notify(new Notice('booking_ban', ['until' => $until->toImmutable()->setTimezone(LocalTime::tz())->format('d.m.Y'), 'days' => self::WINDOW_DAYS], '/me/bookings'));

        return true;
    }

    /** Admin "Reset no-shows": the count, the ban and the points so far. */
    public function reset(User $user): void
    {
        $user->forceFill(['no_show_count' => 0, 'booking_ban_until' => null, 'penalty_reset_at' => now()])->save();
    }
}
