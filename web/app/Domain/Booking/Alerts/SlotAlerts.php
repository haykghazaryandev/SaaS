<?php

namespace App\Domain\Booking\Alerts;

use App\Domain\Booking\BookingException;
use App\Domain\Venues\Availability;
use App\Models\Booking;
use App\Models\Field;
use App\Models\SlotAlert;
use App\Models\User;
use App\Notifications\Notice;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * "Notify me if it frees up" for a booked time.
 * - watch(): only for a time that is taken now (if it's free, just book it); max 10 waiting alerts per player
 * - whenFreed(): called when a booking stops holding its time (cancelled, declined, expired) or moves (changed);
 *   every waiting alert on that field whose time is now free gets one notice (Telegram + in-app) with a ready-to-book link
 */
class SlotAlerts
{
    public const MAX_WAITING = 10;

    public function __construct(private Availability $availability) {}

    public function watch(User $user, Field $field, CarbonImmutable $start, int $minutes): SlotAlert
    {
        $field->loadMissing('venue');
        if ($start->isPast()) {
            throw new BookingException('past');
        }
        if ($this->availability->isFree($field, $start, $minutes)) {
            throw new BookingException('already_free');
        }
        if (SlotAlert::where('user_id', $user->id)->waiting()->count() >= self::MAX_WAITING) {
            throw new BookingException('too_many_alerts');
        }

        return SlotAlert::updateOrCreate(
            ['user_id' => $user->id, 'field_id' => $field->id, 'starts_at' => $start->utc()],
            ['venue_id' => $field->venue_id, 'minutes' => $minutes, 'notified_at' => null],
        );
    }

    public function stop(User $user, SlotAlert $alert): void
    {
        if ($alert->user_id === $user->id) {
            $alert->delete();
        }
    }

    /** A booking let go of (some of) its time: tell everybody waiting for a time on this field that is free now. */
    public function whenFreed(Booking $booking, ?int $fieldId = null, ?CarbonImmutable $at = null): int
    {
        // a moved booking frees its OLD field and day ($fieldId / $at); otherwise its own
        $at ??= $booking->starts_at->toImmutable();
        $day = $at->setTimezone(LocalTime::tz())->startOfDay();
        $n = 0;
        $alerts = SlotAlert::with('user', 'field.venue')->where('field_id', $fieldId ?? $booking->field_id)->waiting()
            ->whereBetween('starts_at', [$day->utc(), $day->addDay()->utc()])->get();
        foreach ($alerts as $a) {
            $start = $a->starts_at->toImmutable()->setTimezone(LocalTime::tz());
            if ($a->user_id === $booking->user_id || ! $this->availability->isFree($a->field, $start, $a->minutes)) {
                continue;
            }
            $a->update(['notified_at' => now()]);
            $a->user->notify(new Notice('slot_free', [
                'venue' => $a->field->venue->name, 'field' => $a->field->format,
                'date' => $start->format('d.m'), 'time' => $start->format('H:i'),
            ], '/places/'.$a->field->venue->slug.'?'.http_build_query([
                'date' => $start->toDateString(), 'field' => $a->field_id, 'minutes' => $a->minutes, 'start' => $start->format('H:i'),
            ])));
            $n++;
        }

        return $n;
    }

    /** When the player books the time themselves, their alert is done. */
    public function booked(Booking $booking): void
    {
        if ($booking->user_id) {
            SlotAlert::where('user_id', $booking->user_id)->where('field_id', $booking->field_id)
                ->where('starts_at', '>=', $booking->starts_at)->where('starts_at', '<', $booking->ends_at)->delete();
        }
    }

    /** The player's alerts for "My bookings": [{id, venue, slug, field, size, date, start, minutes, notified}] */
    public function forUser(User $user): array
    {
        return SlotAlert::with('field.venue')->where('user_id', $user->id)->where('starts_at', '>', now())->orderBy('starts_at')->get()
            ->map(function (SlotAlert $a) {
                $t = $a->starts_at->toImmutable()->setTimezone(LocalTime::tz());

                return ['id' => $a->id, 'venue' => $a->field->venue->name, 'slug' => $a->field->venue->slug, 'field' => $a->field_id,
                    'size' => $a->field->size->value, 'format' => $a->field->format, 'date' => $t->toDateString(), 'start' => $t->format('H:i'),
                    'minutes' => $a->minutes, 'notified' => $a->notified_at !== null];
            })->all();
    }

    /** Clean up old alerts (scheduler, daily). */
    public function prune(): int
    {
        return SlotAlert::where('starts_at', '<', now()->subDay())->delete();
    }
}
