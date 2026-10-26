<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\AfterBooking\Attendance;
use App\Domain\Booking\BookingException;
use App\Domain\Venues\Availability;
use App\Domain\Venues\PriceCalculator;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingSeries;
use App\Models\Field;
use App\Models\User;
use App\Support\Phone;
use Carbon\CarbonImmutable;

/**
 * The place creates and edits bookings itself (phone calls, walk-ins, regular teams).
 * Owner bookings are accepted at once. Player rules (notice, horizon, durations) don't apply.
 */
class OwnerBooking
{
    use BookingWriter;

    public function __construct(private Availability $availability, private PriceCalculator $prices) {}

    /** $data: name, phone?, team_name?, note_owner?, price?, any? (any free court of the kind: "any free table") */
    public function create(User $by, Field $field, CarbonImmutable $start, int $minutes, array $data, ?BookingSeries $series = null): Booking
    {
        if (! empty($data['any'])) {
            [$free, $problem] = $this->availability->freeInGroup($field, $start, $minutes, forPlayer: false);
            if ($problem) {
                throw new BookingException($problem);
            }
            $field = $free;
        } elseif ($problem = $this->availability->problem($field, $start, $minutes, forPlayer: false)) {
            throw new BookingException($problem);
        }
        $phone = Phone::normalize($data['phone'] ?? null);
        $player = $phone ? User::where('phone', $phone)->first() : null;

        return $this->guarded(function () use ($by, $field, $start, $minutes, $data, $phone, $player, $series) {
            $booking = Booking::create([
                'code' => Booking::newCode(),
                'venue_id' => $field->venue_id,
                'field_id' => $field->id,
                'starts_at' => $start->utc(),
                'ends_at' => $start->addMinutes($minutes)->utc(),
                'status' => BookingStatus::Accepted,
                'source' => $series ? BookingSource::Recurring : BookingSource::Owner,
                'user_id' => $player?->id,
                'contact_name' => $data['name'] ?: ($player?->name ?? '—'),
                'contact_phone' => $phone,
                'series_id' => $series?->id,
                'purpose' => ($data['purpose'] ?? 'play') === 'training' ? 'training' : 'play',
                'price' => $data['price'] ?? $this->prices->price($field, $start, $minutes, ($data['purpose'] ?? 'play') === 'training' ? 'training' : 'play'),
                'team_name' => $data['team_name'] ?? null,
                'note_owner' => $data['note_owner'] ?? null,
                'decided_by' => $by->id,
                'decided_at' => now(),
            ]);
            $this->log($booking, 'created_by_owner', $by);

            return $booking;
        });
    }

    /**
     * Weekly booking for a regular team: creates one booking per week, skips weeks that are taken.
     * Returns ['created' => Booking[], 'skipped' => CarbonImmutable[]].
     */
    public function createWeekly(User $by, Field $field, CarbonImmutable $start, int $minutes, int $weeks, array $data): array
    {
        $series = BookingSeries::create([
            'field_id' => $field->id, 'weekday' => $start->dayOfWeek, 'start_time' => $start->format('H:i'), 'duration_min' => $minutes,
            'starts_on' => $start->toDateString(), 'ends_on' => $start->addWeeks($weeks - 1)->toDateString(),
            'contact_name' => $data['name'] ?? null, 'contact_phone' => Phone::normalize($data['phone'] ?? null), 'price' => $data['price'] ?? null, 'created_by' => $by->id,
        ]);
        $created = [];
        $skipped = [];
        for ($i = 0; $i < $weeks; $i++) {
            $t = $start->addWeeks($i);
            try {
                $created[] = $this->create($by, $field, $t, $minutes, $data, $series);
            } catch (BookingException) {
                $skipped[] = $t;
            }
        }

        return compact('created', 'skipped');
    }

    /** Edit time, field, price, contact or notes. $changes keys: field_id, starts_at (local), minutes, price, contact_name, contact_phone, team_name, note_owner */
    public function update(User $by, Booking $booking, array $changes): Booking
    {
        if (! $booking->status->holdsTime()) {
            throw new BookingException('not_active');
        }
        $field = isset($changes['field_id']) ? Field::with('venue')->findOrFail($changes['field_id']) : $booking->loadMissing('field.venue')->field;
        if ($field->venue_id !== $booking->venue_id) {
            throw new BookingException('other_venue');
        }
        $start = isset($changes['starts_at']) ? CarbonImmutable::parse($changes['starts_at']) : $booking->starts_at->toImmutable();
        $minutes = $changes['minutes'] ?? $booking->durationMinutes();
        $timeChanged = $field->id !== $booking->field_id || ! $start->equalTo($booking->starts_at) || $minutes !== $booking->durationMinutes();
        if ($timeChanged && ($problem = $this->availability->problem($field, $start, $minutes, forPlayer: false, ignoreBookingId: $booking->id))) {
            throw new BookingException($problem);
        }

        return $this->guarded(function () use ($by, $booking, $field, $start, $minutes, $changes, $timeChanged) {
            $new = array_filter([
                'field_id' => $field->id,
                'starts_at' => $start->utc(),
                'ends_at' => $start->addMinutes($minutes)->utc(),
                'price' => $changes['price'] ?? ($timeChanged && ! isset($changes['price']) ? $this->prices->price($field, $start, $minutes) : null),
                'contact_name' => $changes['contact_name'] ?? null,
                'contact_phone' => array_key_exists('contact_phone', $changes) ? Phone::normalize($changes['contact_phone']) : null,
                'team_name' => $changes['team_name'] ?? null,
                'note_owner' => $changes['note_owner'] ?? null,
            ], fn ($v) => $v !== null);
            if (array_key_exists('contact_phone', $changes) && blank($changes['contact_phone'])) {
                $new['contact_phone'] = null; // the place removed the phone number
            }
            $old = [];
            foreach ($new as $k => $v) {
                $before = $booking->getAttribute($k);
                $same = $before instanceof \DateTimeInterface ? $before->getTimestamp() === CarbonImmutable::parse($v)->getTimestamp() : $before === $v || ($v !== null && $before == $v);
                if (! $same) {
                    $old[$k] = [$before instanceof \DateTimeInterface ? $before->toIso8601String() : $before, $v instanceof \DateTimeInterface ? $v->toIso8601String() : $v];
                }
            }
            if (! $old) {
                return $booking;
            }
            $booking->update($new);
            $this->log($booking, 'changed', $by, $old);

            return $booking;
        });
    }

    /** "Didn't come" (see Attendance: after the start until 12:00 the next day, not after "arrived"). */
    public function noShow(User $by, Booking $booking): Booking
    {
        return app(Attendance::class)->noShow($by, $booking);
    }
}
