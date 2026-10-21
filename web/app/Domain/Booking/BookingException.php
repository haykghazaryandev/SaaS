<?php

namespace App\Domain\Booking;

use RuntimeException;

/**
 * A booking rule was broken. $reason is a short key (taken, too_soon, outside_hours, …);
 * the text for people comes from lang files: __('booking.error.'.$reason).
 */
class BookingException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(__('booking.error.'.$reason));
    }
}
