<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

/** Player-side rules for a booking. (The owner side checks venue staff in its own controllers.) */
class BookingPolicy
{
    /** A player can cancel only their own booking. */
    public function cancel(User $user, Booking $booking): bool
    {
        return $booking->user_id !== null && $booking->user_id === $user->id;
    }
}
