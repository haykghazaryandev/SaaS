<?php

namespace App\Http\Controllers\Player;

use App\Domain\Booking\Alerts\SlotAlerts;
use App\Http\Controllers\Controller;
use App\Http\Presenters\PlayerVenues;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * My bookings: only what is still ahead (waiting or confirmed, not over yet), plus the
 * "notify me if it frees up" times. Past bookings are not shown (product decision 2026-10-22).
 * No profile block (that lives in Profile); the Telegram banner reads the shared auth.user.telegram.
 */
class MyBookingsController extends Controller
{
    public function __invoke(Request $request, PlayerVenues $present, SlotAlerts $alerts)
    {
        $upcoming = $request->user()->bookings()->holding()->where('ends_at', '>', now())
            ->with(['venue', 'field'])->orderBy('starts_at')->get();

        return Inertia::render('player/MyBookings', [
            'upcoming' => $upcoming->map(fn ($b) => $present->booking($b))->all(),
            // "notify me if it frees up" times (notified = free now, ready to book)
            'alerts' => $alerts->forUser($request->user()),
        ]);
    }
}
