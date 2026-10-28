<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Booking\AfterBooking\Attendance;
use App\Domain\Club\Checkout;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\FieldSize;
use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\Field;
use App\Models\OpeningHour;
use App\Models\Venue;
use App\Support\LocalTime;
use App\Support\Phone;
use Carbon\CarbonImmutable;

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
            'name' => $b->contact_name,
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

    /** How often this player did not come to this place. */
    public static function noShowsHere(int $venueId, int $userId): int
    {
        return self::venueNoShows($venueId)[$userId] ?? 0;
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
            return $f->name;
        }

        return $f->size === FieldSize::Table ? __('owner.field.table') : __('owner.field.'.$f->size->value).' '.$f->format;
    }

    public static function fields(Venue $venue): array
    {
        return $venue->fields->map(fn (Field $f) => [
            'id' => $f->id, 'size' => $f->size->value, 'sport' => $f->sport()->value, 'format' => $f->format, 'label' => self::fieldLabel($f),
            'short' => $f->name ?: $f->format,
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
