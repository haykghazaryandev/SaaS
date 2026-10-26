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
        if (! $venue->hasFeature('booking')) {
            throw new BookingException('feature_off');
        }
        $pending = Booking::query()->where('user_id', $player->id)->where('status', BookingStatus::Pending)->count();
        if ($pending >= $venue->rule('max_pending_per_phone')) {
            throw new BookingException('too_many_pending');
        }
        $purpose = ($extra['purpose'] ?? 'play') === 'training' ? 'training' : 'play';
        if ($purpose === 'training' && $venue->trainingPrice($field->size->value) === null) {
            throw new BookingException('feature_off');
        }
        // "a table at 10:00": the asked court, or any free one of the same kind (ping-pong tables)
        [$free, $problem] = $this->availability->freeInGroup($field, $start, $minutes);
        if ($problem) {
            throw new BookingException($problem);
        }
        $field = $free;

        return $this->guarded(function () use ($player, $field, $venue, $start, $minutes, $extra, $purpose) {
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
                // the player's discount at this place (their card) is in the price from the start
                'price' => $this->discounted($venue, $player->id, $this->prices->price($field, $start, $minutes, $purpose)),
                'purpose' => $purpose,
                'team_name' => $extra['team_name'] ?? null,
                'note_player' => $extra['note'] ?? null,
            ]);
            $this->log($booking, 'requested', $player);

            return $booking;
        });
    }

    private function discounted(\App\Models\Venue $venue, int $userId, int $price): int
    {
        $pct = $venue->hasFeature('wallet') ? \App\Domain\Club\Cards::discountFor($venue->id, $userId) : 0;

        return (int) round($price * (100 - $pct) / 100);
    }
}
