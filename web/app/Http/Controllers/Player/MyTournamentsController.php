<?php

namespace App\Http\Controllers\Player;

use App\Domain\Club\Tournaments;
use App\Http\Controllers\Controller;
use App\Models\TournamentEntry;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * My tournaments: every tournament I registered for (like My bookings), live / upcoming / finished, with where it is,
 * my answer (waiting, accepted, declined), the fee (paid by card or cash, or not paid yet) and my place at the end.
 */
class MyTournamentsController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        $entries = TournamentEntry::where(fn ($q) => $q->where('user_id', $user->id)->when($user->phone, fn ($w) => $w->orWhere('phone', $user->phone)))
            ->with(['tournament.venue:id,name,slug', 'payment:id,method,amount'])->get()
            ->filter(fn ($e) => $e->tournament)->sortBy(fn ($e) => $e->tournament->starts_at)->values();

        return Inertia::render('player/MyTournaments', [
            'items' => $entries->map(function (TournamentEntry $e) {
                $t = $e->tournament;
                $fee = Tournaments::fee($t, $e);

                return [
                    'code' => $t->code, 'title' => $t->title, 'sport' => $t->sport, 'venue' => $t->venue?->name,
                    'when' => $t->starts_at->setTimezone(LocalTime::tz())->toIso8601String(),
                    'phase' => Tournaments::phase($t), 'status' => $e->status, 'place' => $e->place,
                    'option' => $t->options[$e->option]['label'] ?? null, 'fee' => $fee,
                    'paid' => $e->payment ? ['method' => $e->payment->method, 'amount' => $e->payment->amount] : null,
                ];
            })->values(),
        ]);
    }
}
