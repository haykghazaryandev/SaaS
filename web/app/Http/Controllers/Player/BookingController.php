<?php

namespace App\Http\Controllers\Player;

use App\Domain\Auth\PhoneCodes;
use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\RequestBooking;
use App\Domain\Booking\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Field;
use App\Support\LocalTime;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Booking requests from the player web.
 * Logged in: the request is sent at once. Guest: name + phone → code from Telegram → we log them in
 * (remembered, so the next request needs no code) and send the request.
 */
class BookingController extends Controller
{
    /** Guest step 1: name + phone → send a code (same limits as login). */
    public function code(Request $request, PhoneCodes $codes)
    {
        $data = $request->validate(['name' => 'required|string|min:2|max:60', 'phone' => 'required|string|max:20']);
        $phone = Phone::normalize($data['phone']);
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => __('auth.phone.invalid')]);
        }
        $codes->send($phone, $request->ip(), 'booking');
        $request->session()->put('otp_phone', $phone);
        $request->session()->put('booking_name', trim($data['name']));

        return back();
    }

    /** Send the request (guest: with the code from step 1). */
    public function store(Request $request, RequestBooking $action, PhoneCodes $codes)
    {
        $data = $request->validate([
            'field_id' => 'required|integer',
            'date' => 'required|date_format:Y-m-d',
            'start' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'minutes' => 'required|integer|min:30|max:600',
            'team_name' => 'nullable|string|max:40',
            'note' => 'nullable|string|max:200',
            'name' => 'nullable|string|min:2|max:60',
            'code' => 'nullable|digits:4',
        ]);
        $field = Field::with(['venue.openingHours', 'priceRules'])->where('active', true)->find($data['field_id']);
        if (! $field || $field->venue->status !== 'active') {
            throw ValidationException::withMessages(['booking' => __('booking.error.closed')]);
        }

        $user = $request->user();
        if (! $user) {
            $phone = $request->session()->get('otp_phone');
            if (! $phone || empty($data['code'])) {
                throw ValidationException::withMessages(['code' => __('auth.code.expired')]);
            }
            $user = $codes->verify($phone, $data['code']);
            if ($user->isBlocked()) {
                throw ValidationException::withMessages(['code' => __('auth.blocked')]);
            }
            $name = $request->session()->get('booking_name') ?: ($data['name'] ?? null);
            Auth::login($user, remember: true);
            $request->session()->regenerate();
            $request->session()->forget(['otp_phone', 'booking_name']);
        } else {
            $name = $data['name'] ?? null;
        }
        if (! $user->name && $name) {
            $user->forceFill(['name' => $name])->save();
        }

        [$h, $m] = array_map('intval', explode(':', $data['start']));
        $start = LocalTime::at(LocalTime::day($data['date']), $h + $m / 60);
        try {
            $booking = $action->handle($user, $field, $start, (int) $data['minutes'], [
                'team_name' => $data['team_name'] ?? null, 'note' => $data['note'] ?? null, 'name' => $user->name,
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

        return back()->with('success', __('player.my.cancelDone'));
    }
}
