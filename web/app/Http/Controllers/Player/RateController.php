<?php

namespace App\Http\Controllers\Player;

use App\Domain\Booking\AfterBooking\Reviews;
use App\Domain\Booking\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\VenueReview;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** /rate/{booking}: "How was the field?" (also answered with the star buttons in Telegram). */
class RateController extends Controller
{
    public function show(Request $request, Booking $booking, Reviews $reviews)
    {
        $booking->loadMissing('venue', 'field');
        $start = $booking->starts_at->toImmutable()->setTimezone(LocalTime::tz());
        $mine = VenueReview::where('booking_id', $booking->id)->where('user_id', $request->user()->id)->first();

        return Inertia::render('player/Rate', [
            'booking' => ['id' => $booking->id, 'venue' => $booking->venue->name, 'slug' => $booking->venue->slug, 'field' => $booking->field->format,
                'date' => $start->toDateString(), 'start' => $start->format('H:i')],
            'canRate' => $reviews->canRate($booking, $request->user()),
            'review' => $mine ? ['stars' => $mine->stars, 'comment' => $mine->comment] : null,
        ]);
    }

    public function store(Request $request, Booking $booking, Reviews $reviews)
    {
        $data = $request->validate(['stars' => 'required|integer|min:1|max:5', 'comment' => 'nullable|string|max:500']);
        try {
            $reviews->rate($booking, $request->user(), $data['stars'], $data['comment'] ?? null);
        } catch (BookingException $e) {
            throw ValidationException::withMessages(['stars' => $e->getMessage()]);
        }

        return back()->with('success', __('player.rating.thanks'));
    }
}
