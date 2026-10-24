<?php

namespace App\Domain\Booking\AfterBooking;

use App\Domain\Booking\Alerts\SlotAlerts;
use App\Domain\Booking\Events\BookingChanged;
use App\Notifications\Notice;

/**
 * BookingChanged → what follows at the field:
 * - late: the place hears "Team Aram: about 10 min late";
 * - no_show: the player is told (with the place's phone, in case it is a mistake), the penalty is checked,
 *   and if the time is not over yet, people waiting for it hear it is free;
 * - cancelled by the player less than 2 h before: counts as half a no-show;
 * - accepted / changed / cancelled / declined / expired: the booker's team hears it too.
 */
class AfterBookingListener
{
    public function __construct(private Penalties $penalties, private SlotAlerts $alerts) {}

    public function handle(BookingChanged $e): void
    {
        $b = $e->booking->loadMissing('venue', 'field', 'user', 'players.user');
        match ($e->type) {
            'late' => $this->toStaff($e, 'booking_late', ['late' => $b->late_minutes]),
            'no_show' => $this->noShow($e),
            default => null,
        };
        if ($e->type === 'cancelled' && ($e->changes['short'] ?? false) && $b->user) {
            $this->penalties->check($b->user);
        }
        $team = ['accepted' => 'booking_team_confirmed', 'changed' => 'booking_team_changed', 'cancelled' => 'booking_team_cancelled',
            'declined' => 'booking_team_cancelled', 'expired' => 'booking_team_cancelled'][$e->type] ?? null;
        if ($team && ! ($e->type === 'changed' && ! array_intersect(array_keys($e->changes), ['field_id', 'starts_at', 'ends_at']))) {
            foreach ($b->players->where('status', 'joined')->pluck('user')->filter() as $member) {
                $member->notify(new Notice($team, BookingPeople::params($b), '/me/bookings'));
            }
        }
    }

    private function noShow(BookingChanged $e): void
    {
        $b = $e->booking;
        if ($b->user) {
            $b->user->notify(new Notice('booking_no_show', BookingPeople::params($b), '/me/bookings'));
            $this->penalties->check($b->user);
        }
        if ($b->ends_at->isFuture()) {
            $this->alerts->whenFreed($b);
        }
    }

    private function toStaff(BookingChanged $e, string $type, array $extra = []): void
    {
        foreach (BookingPeople::staff($e->booking, $e->actor) as $person) {
            $person->notify(new Notice($type, BookingPeople::params($e->booking) + $extra, '/owner'));
        }
    }
}
