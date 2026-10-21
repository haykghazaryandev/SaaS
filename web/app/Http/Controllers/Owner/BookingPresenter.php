<?php

namespace App\Http\Controllers\Owner;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
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
            'started' => $b->starts_at->isPast(),
            'past' => $b->ends_at->isPast(),
            'user' => $b->relationLoaded('user') && $b->user ? ['id' => $b->user->id, 'name' => $b->user->name, 'avatar' => $b->user->avatarUrl(), 'no_shows' => (int) $b->user->no_show_count] : null,
            'sent_ago' => $b->created_at?->locale(app()->getLocale())->diffForHumans(),
        ];
    }

    /** Full details for the booking sheet, with the history. */
    public static function detail(Booking $b): array
    {
        $b->loadMissing(['user', 'field', 'events.user']);

        return [
            ...self::row($b),
            'field' => self::fieldLabel($b->field),
            'decline_reason' => $b->decline_reason,
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
            $out[] = __('owner.history.reason').': '.$c['reason'];
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

    public static function fieldLabel(Field $f): string
    {
        return __('owner.field.'.$f->size->value).' '.$f->format;
    }

    public static function fields(Venue $venue): array
    {
        return $venue->fields->map(fn (Field $f) => [
            'id' => $f->id, 'size' => $f->size->value, 'format' => $f->format, 'label' => self::fieldLabel($f),
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
