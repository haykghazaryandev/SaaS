<?php

namespace App\Domain\Booking\Events;

use App\Models\Booking;
use App\Models\Closure;
use App\Support\LocalTime;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Live: the times of a place changed (a booking was made, answered, moved or cancelled; a closure added or removed).
 * Owner pages listen on private-venue.{id} and reload; the booking page listens on place.{id} and refreshes its times.
 * Only the local days are sent, no names or phones (place.{id} is public).
 */
class ScheduleChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** Booking columns that only the reminders and questions write: no one sees them change. */
    private const QUIET = ['reminded_day_at', 'reminded_hour_at', 'not_here_asked_at', 'rating_asked_at', 'updated_at'];

    /** @param  string[]  $dates */
    public function __construct(public int $venueId, public array $dates) {}

    public static function forBooking(Booking $b, bool $updated = false): void
    {
        if ($updated && ! array_diff(array_keys($b->getChanges()), self::QUIET)) {
            return;
        }
        $dates = [self::date($b->starts_at)];
        if ($b->wasChanged('starts_at') && $b->getOriginal('starts_at')) {
            $dates[] = self::date($b->getOriginal('starts_at'));
        }
        self::dispatch($b->venue_id, array_values(array_unique($dates)));
    }

    public static function forClosure(Closure $c): void
    {
        $dates = [];
        for ($d = $c->starts_at->toImmutable()->setTimezone(LocalTime::tz())->startOfDay(); $d->lessThan($c->ends_at) && count($dates) < 60; $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }
        self::dispatch($c->venue_id, $dates);
    }

    private static function date($t): string
    {
        return $t->toImmutable()->setTimezone(LocalTime::tz())->toDateString();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('venue.'.$this->venueId), new Channel('place.'.$this->venueId)];
    }

    public function broadcastAs(): string
    {
        return 'schedule.changed';
    }

    public function broadcastWith(): array
    {
        return ['dates' => $this->dates];
    }
}
