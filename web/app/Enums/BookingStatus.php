<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Pending = 'pending';       // request sent, waiting for the place
    case Accepted = 'accepted';     // confirmed
    case Declined = 'declined';     // the place said no
    case Cancelled = 'cancelled';   // cancelled by the player, the place or the system
    case Expired = 'expired';       // the place did not answer in time
    case Completed = 'completed';   // played
    case NoShow = 'no_show';        // the player did not come

    /** Statuses that hold the field (the database exclusion constraint uses the same list). */
    public const HOLDING = ['pending', 'accepted'];

    public function holdsTime(): bool
    {
        return in_array($this->value, self::HOLDING, true);
    }
}
