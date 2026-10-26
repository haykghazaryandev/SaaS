<?php

namespace App\Domain\Venues;

use App\Domain\Booking\BookingException;
use App\Enums\BookingStatus;
use App\Enums\FieldSize;
use App\Models\Booking;
use App\Models\Field;
use App\Models\PriceRule;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;

/**
 * The owner adds, renames, switches off and removes courts of a kind the place already has (a 4th ping-pong table).
 * A new court copies the first court of its kind: lengths, surface, prices. New kinds and sports are added by the admin.
 */
class Courts
{
    public function add(Venue $venue, FieldSize $size, ?string $name = null): Field
    {
        $model = $venue->fields()->where('size', $size->value)->orderBy('sort')->orderBy('id')->with('priceRules')->first();
        if (! $model) {
            throw new BookingException('no_such_kind');
        }

        return DB::transaction(function () use ($venue, $size, $name, $model) {
            $count = $venue->fields()->where('size', $size->value)->count();
            $court = $venue->fields()->create([
                'name' => $this->clean($name) ?? $this->defaultName($size, $count + 1),
                'size' => $size->value, 'format' => $model->format, 'surface' => $model->surface, 'indoor' => $model->indoor,
                'max_players' => $model->max_players, 'durations' => $model->durations, 'default_duration' => $model->default_duration,
                'sort' => (int) $venue->fields()->max('sort') + 1, 'active' => true,
            ]);
            foreach ($model->priceRules as $r) {
                PriceRule::create(['field_id' => $court->id, ...$r->only(['weekdays', 'from_time', 'to_time', 'price_per_hour', 'priority'])]);
            }
            // the first court of a kind gets its name too once there are several ("Table 1", "Table 2")
            if (! $model->name && $count === 1) {
                $model->update(['name' => $this->defaultName($size, 1)]);
            }

            return $court;
        });
    }

    public function update(Field $court, array $data): Field
    {
        if (array_key_exists('active', $data) && ! $data['active']) {
            $this->ensureNotLast($court);
            $this->ensureNoFuture($court);
        }
        $court->update(array_filter([
            'name' => array_key_exists('name', $data) ? ($this->clean($data['name']) ?? $court->name) : null,
            'active' => $data['active'] ?? null,
        ], fn ($v) => $v !== null));

        return $court;
    }

    /** Removed for good when it was never booked; otherwise switched off, so the booking history stays. Returns "deleted" or "off". */
    public function remove(Field $court): string
    {
        $this->ensureNotLast($court);
        $this->ensureNoFuture($court);
        if (Booking::where('field_id', $court->id)->exists()) {
            $court->update(['active' => false]);

            return 'off';
        }
        $court->delete();

        return 'deleted';
    }

    private function ensureNotLast(Field $court): void
    {
        $others = Field::where('venue_id', $court->venue_id)->where('size', $court->size->value)->where('active', true)->where('id', '!=', $court->id)->exists();
        if (! $others) {
            throw new BookingException('last_court');
        }
    }

    private function ensureNoFuture(Field $court): void
    {
        $future = Booking::where('field_id', $court->id)->whereIn('status', BookingStatus::HOLDING)->where('ends_at', '>', now())->exists();
        if ($future) {
            throw new BookingException('court_has_bookings');
        }
    }

    private function defaultName(FieldSize $size, int $n): string
    {
        return __('owner.courts.name_'.$size->value, ['n' => $n]);
    }

    private function clean(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : mb_substr($name, 0, 40);
    }
}
