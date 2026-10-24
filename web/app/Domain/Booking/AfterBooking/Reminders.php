<?php

namespace App\Domain\Booking\AfterBooking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Notifications\Notice;

/**
 * Scheduled every minute (routes/console.php). Each message goes once (the *_at columns):
 * - 24 h before: "Tomorrow 21:00 at Zangak" to the booker and the team (not for bookings made less than a day ahead);
 * - 1 h before: the same, and the booker gets "I'm late ~N min" / "Can't come" buttons;
 * - start + grace time (15 min), not marked as arrived: the place is asked "Team not here?" (Arrived / Didn't come / Wait);
 * - "How was the field?": 30 min after the end when the place marked the team as arrived, otherwise once the
 *   no-show deadline (12:00 next day) has passed and the game still counts as played. Never for a no-show.
 */
class Reminders
{
    public function run(): array
    {
        return ['day' => $this->dayBefore(), 'hour' => $this->hourBefore(), 'not_here' => $this->notHere(), 'rate' => $this->askRatings()];
    }

    public function dayBefore(): int
    {
        $due = Booking::with(['venue', 'field', 'user', 'players.user'])->where('status', BookingStatus::Accepted)->whereNull('reminded_day_at')
            ->whereBetween('starts_at', [now()->addHours(3), now()->addHours(24)])
            ->whereRaw("created_at < starts_at - interval '25 hours'")->get();
        foreach ($due as $b) {
            $b->update(['reminded_day_at' => now()]);
            foreach ($b->attendees() as $person) {
                $person->notify(new Notice('booking_reminder_day', BookingPeople::params($b), '/me/bookings'));
            }
        }

        return $due->count();
    }

    public function hourBefore(): int
    {
        $due = Booking::with(['venue', 'field', 'user', 'players.user'])->where('status', BookingStatus::Accepted)->whereNull('reminded_hour_at')
            ->whereBetween('starts_at', [now()->addMinutes(10), now()->addMinutes(60)])
            ->whereRaw("created_at < starts_at - interval '90 minutes'")->get();
        foreach ($due as $b) {
            $b->update(['reminded_hour_at' => now()]);
            foreach ($b->attendees() as $person) {
                $late = [['telegram.button.late', "late:{$b->id}:10", ['min' => 10]], ['telegram.button.late', "late:{$b->id}:20", ['min' => 20]]];
                $buttons = $person->id === $b->user_id ? [$late, [['telegram.button.cant_come', "cantcome:{$b->id}"]]] : [$late];
                $person->notify(new Notice('booking_reminder_hour', BookingPeople::params($b), '/me/bookings', $buttons));
            }
        }

        return $due->count();
    }

    public function notHere(): int
    {
        $n = 0;
        $due = Booking::with(['venue', 'field', 'user'])->where('status', BookingStatus::Accepted)->whereNull('arrived_at')->whereNull('not_here_asked_at')
            ->where('starts_at', '<', now())->where('ends_at', '>', now())->get();
        foreach ($due as $b) {
            if ($b->starts_at->copy()->addMinutes((int) $b->venue->rule('late_grace_min'))->isFuture()) {
                continue;
            }
            $b->update(['not_here_asked_at' => now()]);
            $late = $b->late_minutes ? ['late' => $b->late_minutes] : [];
            foreach (BookingPeople::staff($b) as $person) {
                $person->notify(new Notice('booking_not_here', BookingPeople::params($b) + $late, '/owner', [
                    [['telegram.button.arrived', "arrived:{$b->id}"], ['telegram.button.no_show', "noshow:{$b->id}"]],
                    [['telegram.button.wait', "wait:{$b->id}"]],
                ]));
            }
            $n++;
        }

        return $n;
    }

    public function askRatings(): int
    {
        $n = 0;
        $due = Booking::with(['venue', 'field', 'user', 'players.user'])->where('status', BookingStatus::Completed)->whereNull('rating_asked_at')
            ->where('ends_at', '<', now()->subMinutes(30))->where('ends_at', '>', now()->subDays(2))->get();
        foreach ($due as $b) {
            // the place confirmed they came, or nobody marked a no-show until the deadline
            if (! $b->arrived_at && now()->lessThan(BookingPeople::noShowDeadline($b))) {
                continue;
            }
            $b->update(['rating_asked_at' => now()]);
            $stars = array_map(fn ($s) => [str_repeat('⭐', $s), "rate:{$b->id}:{$s}"], [1, 2, 3, 4, 5]);
            foreach ($b->attendees() as $person) {
                $person->notify(new Notice('booking_rate', BookingPeople::params($b), '/rate/'.$b->id, [array_slice($stars, 0, 3), array_slice($stars, 3)]));
            }
            $n++;
        }

        return $n;
    }
}
