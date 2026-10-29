<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Venues\Availability;
use App\Models\Booking;
use App\Models\Venue;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Numbers for the owner pages: bookings of a date range, occupancy per field and day. */
final class VenueStats
{
    public function __construct(private Availability $availability) {}

    /** Bookings of the place that overlap local days [$from, $to), any status unless given. */
    public function bookings(Venue $venue, CarbonImmutable $from, CarbonImmutable $to, ?array $statuses = null): Collection
    {
        return Booking::query()->where('venue_id', $venue->id)
            ->where('starts_at', '<', $to->utc())->where('ends_at', '>', $from->utc())
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->with('user:id,name,phone,no_show_count')
            ->orderBy('starts_at')->get();
    }

    /** Share (0–100) of the opening hours of a local day that are booked or requested on a field. */
    public function occupancy(Venue $venue, int $fieldId, CarbonImmutable $day, Collection $bookings): int
    {
        $hours = $this->availability->hours($venue, $day);
        if (! $hours || $hours[1] <= $hours[0]) {
            return 0;
        }
        $from = $day->startOfDay();
        $to = $from->addDay();
        $minutes = $bookings->where('field_id', $fieldId)
            ->filter(fn (Booking $b) => in_array($b->status->value, BookingPresenter::USED, true))
            ->sum(function (Booking $b) use ($from, $to) {
                $s = max($b->starts_at->getTimestamp(), $from->getTimestamp());
                $e = min($b->ends_at->getTimestamp(), $to->getTimestamp());

                return max(0, $e - $s) / 60;
            });

        return (int) min(100, round($minutes / (($hours[1] - $hours[0]) * 60) * 100));
    }

    /** Pending requests that can still be answered (start in the future). */
    public function pendingQuery(Venue $venue)
    {
        return Booking::query()->where('venue_id', $venue->id)->where('status', 'pending')->where('starts_at', '>', now());
    }

    /**
     * A day's four numbers on top of the schedule (today, or the day picked): its bookings, the requests waiting for it,
     * its trainings, how full each court is.
     */
    public function dayNumbers(Venue $venue, ?CarbonImmutable $day = null): array
    {
        $day ??= self::today();
        $list = $this->bookings($venue, $day, $day->addDay());
        // what the schedule lists that day: the place said yes (to come, playing or played)
        $said = $list->filter(fn (Booking $b) => in_array(BookingPresenter::kind($b), ['accepted', 'owner'], true));

        return [
            'date' => $day->toDateString(),
            'today' => $said->count(),
            'pending' => $this->pendingQuery($venue)->where('starts_at', '>=', $day->utc())->where('starts_at', '<', $day->addDay()->utc())->count(),
            'trainings' => $said->where('purpose', 'training')->count(),
            'occupancy' => $venue->fields->mapWithKeys(fn ($f) => [$f->id => $this->occupancy($venue, $f->id, $day, $list)])->all(),
        ];
    }

    public static function today(): CarbonImmutable
    {
        return LocalTime::now()->startOfDay();
    }
}
