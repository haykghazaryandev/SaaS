<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\BookingException;
use App\Domain\Venues\Availability;
use App\Domain\Venues\PriceCalculator;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Field;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A player asks a place for a field: the booking starts as "pending" and the place accepts or declines it.
 * Used by the web (guest or logged in) and later by the mobile API.
 */
class RequestBooking
{
    use BookingWriter;

    public function __construct(private Availability $availability, private PriceCalculator $prices) {}

    public function handle(User $player, Field $field, CarbonImmutable $start, int $minutes, array $extra = []): Booking
    {
        if ($player->isBlocked()) {
            throw new BookingException('blocked');
        }
        if ($player->isBookingBanned()) {
            throw new BookingException('banned');
        }
        $venue = $field->loadMissing('venue')->venue;
        $pending = Booking::query()->where('user_id', $player->id)->where('status', BookingStatus::Pending)->count();
        if ($pending >= $venue->rule('max_pending_per_phone')) {
            throw new BookingException('too_many_pending');
        }
        if ($problem = $this->availability->problem($field, $start, $minutes)) {
            throw new BookingException($problem);
        }

        return $this->guarded(function () use ($player, $field, $venue, $start, $minutes, $extra) {
            $booking = Booking::create([
                'code' => Booking::newCode(),
                'venue_id' => $venue->id,
                'field_id' => $field->id,
                'starts_at' => $start->utc(),
                'ends_at' => $start->addMinutes($minutes)->utc(),
                'status' => BookingStatus::Pending,
                'source' => $extra['source'] ?? ($player->isRegistered() ? BookingSource::Player : BookingSource::WebGuest),
                'user_id' => $player->id,
                'contact_name' => $player->name ?: ($extra['name'] ?? '—'),
                'contact_phone' => $player->phone,
                'game_id' => $extra['game_id'] ?? null,
                'price' => $this->prices->price($field, $start, $minutes),
                'team_name' => $extra['team_name'] ?? null,
                'note_player' => $extra['note'] ?? null,
            ]);
            $this->log($booking, 'requested', $player);

            return $booking;
        });
    }
}
