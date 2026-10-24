<?php

namespace App\Domain\Booking\AfterBooking;

use App\Models\Booking;
use App\Models\User;
use App\Support\LocalTime;
use App\Support\Phone;
use Illuminate\Support\Collection;

/** Who to tell about a booking, and the common :placeholders of its messages. */
class BookingPeople
{
    /** Owners and staff of the place who connected Telegram. */
    public static function staff(Booking $b, ?User $except = null): Collection
    {
        return User::query()
            ->whereIn('id', fn ($q) => $q->select('user_id')->from('venue_users')->where('venue_id', $b->venue_id))
            ->when($except, fn ($q) => $q->where('id', '!=', $except->id))
            ->get();
    }

    /** :venue :field :date :time :code :name :phone */
    public static function params(Booking $b): array
    {
        $b->loadMissing('venue', 'field', 'user');
        $start = $b->starts_at->toImmutable()->setTimezone(LocalTime::tz());
        $end = $b->ends_at->toImmutable()->setTimezone(LocalTime::tz());

        return [
            'venue' => $b->venue->name, 'field' => $b->field->format, 'date' => $start->format('d.m'),
            'time' => $start->format('H:i').'–'.$end->format('H:i'), 'start' => $start->format('H:i'), 'code' => $b->code,
            'name' => $b->team_name ?: ($b->user?->name ?? $b->contact_name), 'phone' => Phone::format($b->contact_phone) ?: '—',
            'place_phone' => Phone::format($b->venue->phone) ?: '—',
        ];
    }

    /** The no-show can be marked until 12:00 the day after the game (the place may forget during the evening). */
    public static function noShowDeadline(Booking $b): \Carbon\CarbonImmutable
    {
        return $b->ends_at->toImmutable()->setTimezone(LocalTime::tz())->addDay()->setTime(12, 0)->utc();
    }
}
