<?php

namespace App\Http\Controllers\Player;

use App\Domain\Booking\AfterBooking\Attendance;
use App\Domain\Booking\AfterBooking\BookingTeam;
use App\Domain\Booking\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPlayer;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** My bookings: the team of a booking (add friends, invite a phone, the invite link), leaving it, "running late". */
class BookingTeamController extends Controller
{
    public function friends(Request $request, Booking $booking, BookingTeam $team)
    {
        $data = $request->validate(['user_ids' => 'required|array|max:22', 'user_ids.*' => 'integer']);
        $n = $this->attempt(fn () => $team->addFriends($booking, $request->user(), $data['user_ids']));

        return back()->with('success', trans_choice('player.team.added', $n, ['n' => $n]));
    }

    public function phone(Request $request, Booking $booking, BookingTeam $team)
    {
        $data = $request->validate(['phone' => 'required|string|max:20']);
        [$result, $user] = $this->attempt(fn () => $team->invitePhone($booking, $request->user(), $data['phone']), 'phone');

        return back()->with('success', $result === 'added' ? __('player.team.phone_added', ['name' => $user->name]) : __('player.team.phone_invited'));
    }

    public function destroy(Request $request, Booking $booking, BookingPlayer $player, BookingTeam $team)
    {
        $this->attempt(fn () => $team->remove($booking, $request->user(), $player));

        return back();
    }

    public function leave(Request $request, Booking $booking, BookingTeam $team)
    {
        $team->leave($booking, $request->user());

        return back()->with('success', __('player.team.left'));
    }

    /** /join/{token}: the invite link of a booking (logged-out people log in first and come back). */
    public function join(Request $request, string $token, BookingTeam $team)
    {
        $booking = Booking::where('invite_token', $token)->firstOrFail();
        try {
            $team->join($booking, $request->user());
        } catch (BookingException $e) {
            return redirect()->route('me.bookings')->with('error', $e->getMessage());
        }

        return redirect()->route('me.bookings')->with('success', __('player.team.joined', ['venue' => $booking->venue->name]));
    }

    public function late(Request $request, Booking $booking, Attendance $attendance)
    {
        $data = $request->validate(['minutes' => 'required|integer']);
        $this->attempt(fn () => $attendance->late($request->user(), $booking, (int) $data['minutes']));

        return back()->with('success', __('player.late.sent', ['min' => (int) $data['minutes']]));
    }

    private function attempt(callable $fn, string $field = 'team'): mixed
    {
        try {
            return $fn();
        } catch (BookingException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }
}
