<?php

namespace App\Http\Controllers\Player;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\PlayerVenues;
use App\Support\Phone;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** My bookings: upcoming (waiting or confirmed, not over yet) and past (everything else). */
class MyBookingsController extends Controller
{
    public function __invoke(Request $request, PlayerVenues $present)
    {
        $user = $request->user();
        $all = $user->bookings()->with(['venue', 'field'])->orderBy('starts_at')->get();
        [$up, $past] = $all->partition(fn ($b) => $b->status->holdsTime() && $b->ends_at->isFuture());
        $playedMin = $past->where('status', BookingStatus::Completed)->sum(fn ($b) => $b->durationMinutes());

        return Inertia::render('player/MyBookings', [
            'tab' => $request->query('tab') === 'past' ? 'past' : 'up',
            'profile' => ['name' => $user->name, 'phone' => Phone::format($user->phone), 'telegram' => $user->hasTelegram(), 'registered' => $user->isRegistered()],
            'upcoming' => $up->values()->map(fn ($b) => $present->booking($b))->all(),
            'past' => $past->sortByDesc('starts_at')->take(60)->values()->map(fn ($b) => $present->booking($b))->all(),
            'stats' => ['up' => $up->count(), 'waiting' => $up->where('status', BookingStatus::Pending)->count(), 'hours' => round($playedMin / 60, 1)],
        ]);
    }
}
