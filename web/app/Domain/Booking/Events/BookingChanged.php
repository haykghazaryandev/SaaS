<?php

namespace App\Domain\Booking\Events;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something happened to a booking. $type: requested | accepted | declined | changed | cancelled | expired | no_show | created_by_owner.
 * Listeners send Telegram messages and update the owner's live schedule.
 */
class BookingChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Booking $booking,
        public readonly string $type,
        public readonly ?User $actor = null,
        public readonly array $changes = [],
    ) {}
}
