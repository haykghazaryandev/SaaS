<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Booking\AfterBooking\Attendance;
use App\Domain\Club\Checkout;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\FieldSize;
use App\Enums\Sport;
use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\Field;
use App\Models\OpeningHour;
use App\Models\SportRating;
use App\Models\Venue;
use App\Support\LocalTime;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Turns bookings into the arrays the owner pages use. Times are local decimal hours of the booking's local day. */
final class BookingPresenter
{
    /** Statuses that count as "the field is used" for occupancy. */
    public const USED = ['pending', 'accepted', 'completed', 'no_show'];

    /**
     * Visual kind: pending (amber), accepted (green, from players), owner (violet, made by the place), noshow,
     * expired (grey outline: nobody answered, also a request whose start has passed before the job ran), off (declined/cancelled).
     */
    public static function kind(Booking $b): string
    {
        return match ($b->status) {
            BookingStatus::Pending => $b->starts_at->isPast() ? 'expired' : 'pending',
            BookingStatus::Expired => 'expired',
            BookingStatus::Accepted, BookingStatus::Completed => in_array($b->source, [BookingSource::Owner, BookingSource::Recurring], true) ? 'owner' : 'accepted',
            BookingStatus::NoShow => 'noshow',
            default => 'off',
        };
    }

    public static function row(Booking $b): array
    {
        $start = $b->starts_at->toImmutable()->setTimezone(LocalTime::tz());
        $day = $start->startOfDay();
        $from = LocalTime::hourOf($start, $day);

        return [
            'id' => $b->id,
            'code' => $b->code,
            'field_id' => $b->field_id,
            'date' => $day->toDateString(),
            'start' => round($from, 2),
            'end' => round($from + $b->durationMinutes() / 60, 2),
            'minutes' => $b->durationMinutes(),
            'status' => $b->status->value,
            'source' => $b->source->value,
            'kind' => self::kind($b),
            'name' => self::contactName($b),
            'team' => $b->team_name,
            'phone' => $b->contact_phone ? Phone::format($b->contact_phone) : null,
            'tel' => $b->contact_phone,
            'price' => $b->price,
            'note_player' => $b->note_player,
            'note_owner' => $b->note_owner,
            'weekly' => $b->series_id !== null,
            'purpose' => $b->purpose ?? 'play',
            'paid' => $b->paid_at !== null,
            'started' => $b->starts_at->isPast(),
            // the desk: the game was started (the bill runs only then), is being played now, can be started now
            'game_started' => $b->started_at !== null,
            'playing' => $b->started_at !== null && ! $b->stopped_at && ! $b->paid_at,
            'can_start' => ! $b->started_at && ! $b->paid_at && $b->status === BookingStatus::Accepted
                && $b->starts_at->lessThanOrEqualTo(now()->addMinutes(Checkout::EARLY_MINUTES)) && $b->ends_at->isFuture(),
            // how often this player did not come to THIS place (the place sees only its own)
            'no_shows_here' => $b->user_id ? self::noShowsHere($b->venue_id, $b->user_id) : 0,
            // their record at this place: played, didn't come, cancelled late, came late (the owner decides who gets a time)
            'history' => $b->user_id ? self::history($b->venue_id, $b->user_id) : null,
            'past' => $b->ends_at->isPast(),
            // at the field: "Arrived ✓" (optional), "about 10 min late", "Didn't come" until 12:00 the next day
            'arrived' => $b->arrived_at !== null,
            'late_minutes' => $b->late_minutes,
            'can_arrive' => Attendance::canArrive($b),
            'can_no_show' => Attendance::canNoShow($b),
            'user' => $b->relationLoaded('user') && $b->user ? ['id' => $b->user->id, 'name' => $b->user->name, 'avatar' => $b->user->avatarUrl(), 'no_shows' => (int) $b->user->no_show_count] : null,
            'sent_ago' => $b->created_at?->locale(app()->getLocale())->diffForHumans(),
        ];
    }

    /** The booking's name; a walk-in made at the desk ("Walk-in" in any language) in the reader's language. */
    public static function contactName(Booking $b): string
    {
        $name = (string) $b->contact_name;
        foreach (['en', 'hy', 'ru'] as $l) {
            if ($name === __('owner.club.walk_in', [], $l)) {
                return __('owner.club.walk_in');
            }
        }

        return $name;
    }

    /** How often this player did not come to this place. */
    public static function noShowsHere(int $venueId, int $userId): int
    {
        return self::venueNoShows($venueId)[$userId] ?? 0;
    }

    /**
     * Requests waiting, with what helps to choose when several asked for the same court and time: each player's rating
     * (the place's sport), how far to trust them here (trust), the competing requests and the order among them (rank,
     * 1 = the best choice, the preferred one). The order: the best record here (played, minus no-shows ×3, late cancels
     * ×2, late ×1), then the higher rating, then whoever asked first.
     * Trust: risky (didn't come, or cancelled late twice), mixed (cancelled late or came late), good (played here, no
     * trouble), new (no record here).
     *
     * @param  Collection<int, Booking>  $requests
     * @return array<int, array{rating: ?int, trust: string, competing: int[], rank: ?int, preferred: bool}>
     */
    public static function choosing($requests, Venue $venue): array
    {
        $sport = ($venue->sports()[0] ?? Sport::Football)->value;
        $ratings = SportRating::whereIn('user_id', $requests->pluck('user_id')->filter()->unique())->where('sport', $sport)->pluck('rating', 'user_id');
        $hist = fn (Booking $b) => $b->user_id ? self::history($venue->id, $b->user_id) : ['played' => 0, 'no_shows' => 0, 'late_cancels' => 0, 'lates' => 0];
        $score = function (Booking $b) use ($ratings, $hist) {
            $h = $hist($b);

            return [$h['played'] - 3 * $h['no_shows'] - 2 * $h['late_cancels'] - $h['lates'], (int) ($ratings[$b->user_id] ?? 0), -$b->created_at->getTimestamp()];
        };
        $out = [];
        foreach ($requests as $b) {
            $competing = $requests->filter(fn (Booking $o) => $o->id !== $b->id && $o->field_id === $b->field_id && $o->starts_at < $b->ends_at && $o->ends_at > $b->starts_at);
            $rank = $competing->isEmpty() ? null : 1 + $competing->filter(fn (Booking $o) => $score($o) > $score($b))->count();
            $out[$b->id] = ['rating' => isset($ratings[$b->user_id]) ? (int) $ratings[$b->user_id] : null, 'trust' => self::trust($hist($b)),
                'competing' => $competing->pluck('id')->values()->all(), 'rank' => $rank, 'preferred' => $rank === 1];
        }

        return $out;
    }

    /** risky | mixed | good | new, from a player's record here (see choosing()). */
    public static function trust(array $h): string
    {
        return match (true) {
            $h['no_shows'] > 0 || $h['late_cancels'] >= 2 => 'risky',
            $h['late_cancels'] > 0 || $h['lates'] > 0 => 'mixed',
            $h['played'] > 0 => 'good',
            default => 'new',
        };
    }

    /** A player's record at this place: ['played', 'no_shows', 'late_cancels', 'lates']. */
    public static function history(int $venueId, int $userId): array
    {
        return self::venueHistory($venueId)[$userId] ?? ['played' => 0, 'no_shows' => 0, 'late_cancels' => 0, 'lates' => 0];
    }

    /**
     * Every player's record at the place, counted once per request: games played (started at the desk or completed),
     * no-shows, cancels less than 2 h before the start (Penalties), late (they said so, or the game started 10+ min late).
     */
    public static function venueHistory(int $venueId): array
    {
        return once(function () use ($venueId) {
            $rows = Booking::where('venue_id', $venueId)->whereNotNull('user_id')->groupBy('user_id')->selectRaw("user_id,
                count(*) filter (where status = 'completed' or started_at is not null) as played,
                count(*) filter (where status = 'no_show') as no_shows,
                count(*) filter (where late_minutes is not null or (started_at is not null and started_at > starts_at + interval '10 minutes')) as lates")
                ->get()->keyBy('user_id');
            $short = BookingEvent::where('type', 'cancelled')->where('changes->short', true)
                ->join('bookings', 'bookings.id', '=', 'booking_events.booking_id')->where('bookings.venue_id', $venueId)->whereNotNull('bookings.user_id')
                ->groupBy('bookings.user_id')->selectRaw('bookings.user_id, count(*) as n')->pluck('n', 'bookings.user_id');
            $out = [];
            foreach ($rows->keys()->merge($short->keys())->unique() as $uid) {
                $r = $rows[$uid] ?? null;
                $out[$uid] = ['played' => (int) ($r->played ?? 0), 'no_shows' => (int) ($r->no_shows ?? 0), 'late_cancels' => (int) ($short[$uid] ?? 0), 'lates' => (int) ($r->lates ?? 0)];
            }

            return $out;
        });
    }

    /** user id => no-shows at the place, counted once per request (once(): fresh for every request, also on long-running servers). */
    public static function venueNoShows(int $venueId): array
    {
        return once(fn () => Booking::where('venue_id', $venueId)->where('status', BookingStatus::NoShow->value)->whereNotNull('user_id')
            ->groupBy('user_id')->selectRaw('user_id, count(*) as n')->pluck('n', 'user_id')->map(fn ($n) => (int) $n)->all());
    }

    /** Full details for the booking sheet, with the history. */
    public static function detail(Booking $b): array
    {
        $b->loadMissing(['user', 'field', 'events.user']);

        return [
            ...self::row($b),
            'field' => self::fieldLabel($b->field),
            'decline_reason' => $b->decline_reason,
            // the bill: things added during the game
            'items' => $b->items()->get(['id', 'name', 'qty', 'price_each'])->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'qty' => $i->qty, 'sum' => $i->qty * $i->price_each])->all(),
            'cancel_reason' => $b->cancel_reason,
            'cancelled_by' => $b->cancelled_by,
            'events' => $b->events->sortBy('id')->values()->map(fn (BookingEvent $e) => [
                'type' => $e->type,
                'label' => __('owner.history.'.$e->type),
                'who' => $e->user ? ($e->user_id === $b->user_id ? $e->user->shortName().' · '.__('owner.history.player') : $e->user->shortName()) : __('owner.history.system'),
                'at' => $e->created_at?->setTimezone(LocalTime::tz())->locale(app()->getLocale())->isoFormat('D MMM, HH:mm'),
                'details' => self::changeLines($e),
            ])->all(),
        ];
    }

    /** "Time: 19:00 → 20:00", "Price: 20 000 → 25 000", "Reason: …" */
    private static function changeLines(BookingEvent $e): array
    {
        $c = $e->changes ?? [];
        $out = [];
        if (isset($c['reason'])) {
            $out[] = __('common.reason').': '.$c['reason'];
        }
        if ($e->type !== 'changed') {
            return $out;
        }
        $fmt = function (string $key, $v) {
            if ($v === null || $v === '') {
                return '—';
            }

            return match ($key) {
                'starts_at', 'ends_at' => CarbonImmutable::parse($v)->setTimezone(LocalTime::tz())->locale(app()->getLocale())->isoFormat('D MMM HH:mm'),
                'price' => number_format((int) $v, 0, '.', ' ').' ֏',
                'field_id' => ($f = Field::find($v)) ? self::fieldLabel($f) : (string) $v,
                'contact_phone' => Phone::format($v),
                default => (string) $v,
            };
        };
        foreach ($c as $key => $pair) {
            if (! is_array($pair) || count($pair) !== 2) {
                continue;
            }
            $out[] = __('owner.history.fields.'.$key).': '.$fmt($key, $pair[0]).' → '.$fmt($key, $pair[1]);
        }

        return $out;
    }

    /** "Small field 5×5", or the court's own name ("Table 3"). */
    public static function fieldLabel(Field $f): string
    {
        if ($f->name) {
            return $f->displayName();
        }

        return $f->size === FieldSize::Table ? __('owner.field.table') : __('owner.field.'.$f->size->value).' '.$f->format;
    }

    public static function fields(Venue $venue): array
    {
        return $venue->fields->map(fn (Field $f) => [
            'id' => $f->id, 'size' => $f->size->value, 'sport' => $f->sport()->value, 'format' => $f->format, 'label' => self::fieldLabel($f),
            'short' => $f->displayName() ?: $f->format,
            'durations' => $f->durations, 'default_duration' => $f->default_duration, 'active' => $f->active,
        ])->values()->all();
    }

    /** Opening hours per weekday (0 = Sunday): [open, close] in decimal hours, or null when closed. */
    public static function hours(Venue $venue): array
    {
        $out = array_fill(0, 7, null);
        foreach ($venue->openingHours as $oh) {
            $out[$oh->weekday] = [OpeningHour::toHours($oh->opens_at), OpeningHour::toHours($oh->closes_at)];
        }

        return $out;
    }
}
