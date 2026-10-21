<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Booking\BookingException;
use App\Enums\VenueRole;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Base for the owner web. EnsureVenueStaff puts the current place in the request ("venue", "venueRole").
 * Staff may work with bookings; only the owner role may change settings.
 */
abstract class OwnerController extends Controller
{
    protected function venue(Request $request): Venue
    {
        return $request->attributes->get('venue');
    }

    protected function isOwner(Request $request): bool
    {
        return $request->attributes->get('venueRole') === VenueRole::Owner->value;
    }

    protected function ensureOwner(Request $request): void
    {
        abort_unless($this->isOwner($request), 403, __('owner.err.owner_only'));
    }

    /** A booking of another place: 403. */
    protected function ensureSameVenue(Request $request, Booking $booking): void
    {
        abort_unless($booking->venue_id === $this->venue($request)->id, 403);
    }

    /** Runs a booking action; a broken rule becomes a form error ("booking") with the translated text. */
    protected function attempt(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (BookingException $e) {
            throw ValidationException::withMessages(['booking' => __('booking.error.'.$e->reason)]);
        }
    }
}
