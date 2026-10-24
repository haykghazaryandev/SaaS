<?php

namespace App\Http\Controllers\Player;

use App\Domain\Booking\Alerts\SlotAlerts;
use App\Http\Controllers\Controller;
use App\Http\Presenters\PlayerVenues;
use App\Models\Booking;
use App\Models\BookingPlayer;
use App\Models\User;
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
        $user = $request->user();
        // mine, and the ones a friend added me to (team)
        $upcoming = Booking::holding()->where('ends_at', '>', now())
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhereIn('id', BookingPlayer::select('booking_id')->where('user_id', $user->id)->where('status', 'joined')))
            ->with(['venue', 'field', 'user', 'players.user'])->orderBy('starts_at')->get();
        $friends = User::whereIn('id', $user->friendIds())->orderBy('name')->get(['id', 'name', 'username', 'avatar_path']);

        return Inertia::render('player/MyBookings', [
            'upcoming' => $upcoming->map(fn ($b) => $present->booking($b, $user))->all(),
            // for "Add your team": friends to pick from; ?team=<id> opens it right after booking
            'friends' => $friends->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'username' => $f->username, 'avatar' => $f->avatarUrl()])->all(),
            'openTeam' => $request->integer('team') ?: null,
            // "notify me if it frees up" times (notified = free now, ready to book)
            'alerts' => $alerts->forUser($request->user()),
        ]);
    }
}
