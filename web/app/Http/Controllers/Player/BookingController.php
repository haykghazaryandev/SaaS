<?php

namespace App\Http\Controllers\Player;

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\RequestBooking;
use App\Domain\Booking\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Field;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Booking requests from the player web.
 * The player must be logged in. Guests log in right in the booking sheet with "Log in with Telegram"
 * (free; Telegram confirms the phone) or by email, then the request is sent.
 */
class BookingController extends Controller
{
    /** Send the request (guest: with the code from step 1). */
    public function store(Request $request, RequestBooking $action)
    {
        $data = $request->validate([
            'field_id' => 'required|integer',
            'date' => 'required|date_format:Y-m-d',
            'start' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'minutes' => 'required|integer|min:30|max:600',
            'team_name' => 'nullable|string|max:40',
            'note' => 'nullable|string|max:200',
            'name' => 'nullable|string|min:2|max:60',
            'purpose' => 'nullable|in:play,training',
        ]);
        $field = Field::with(['venue.openingHours', 'priceRules'])->where('active', true)->find($data['field_id']);
        if (! $field || $field->venue->status !== 'active') {
            throw ValidationException::withMessages(['booking' => __('booking.error.closed')]);
        }

        // guests log in first (free with Telegram in the booking sheet, or by email)
        $user = $request->user();
        if (! $user) {
            throw ValidationException::withMessages(['booking' => __('player.verify.login_first')]);
        }
        if (! $user->name && ! empty($data['name'])) {
            $user->forceFill(['name' => $data['name']])->save();
        }

        [$h, $m] = array_map('intval', explode(':', $data['start']));
        $start = LocalTime::at(LocalTime::day($data['date']), $h + $m / 60);
        try {
            $booking = $action->handle($user, $field, $start, (int) $data['minutes'], [
                'team_name' => $data['team_name'] ?? null, 'note' => $data['note'] ?? null, 'name' => $user->name, 'purpose' => $data['purpose'] ?? 'play',
            ]);
        } catch (BookingException $e) {
            throw ValidationException::withMessages(['booking' => $e->getMessage()]);
        }

        return redirect()->route('places.show', [
            'venue' => $field->venue->slug, 'date' => $data['date'], 'field' => $field->id, 'minutes' => $data['minutes'],
        ])->with('booked', $booking->id);
    }

    /** The player cancels their own request or booking. */
    public function cancel(Request $request, Booking $booking, CancelBooking $cancel)
    {
        Gate::authorize('cancel', $booking);
        try {
            $cancel->handle($booking, $request->user(), 'player', $request->input('reason') ?: null);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('player.my_bookings.cancel_done'));
    }
}
