<?php

namespace App\Http\Controllers\Player;

use App\Domain\Club\Cards;
use App\Domain\Club\Ratings;
use App\Http\Controllers\Controller;
use App\Models\CardEntry;
use App\Models\Venue;
use App\Models\VenueCard;
use App\Support\LocalTime;
use App\Support\Qr;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * My cards: one per club. Each has its own QR (only that club's staff can open it), the balance, the discount
 * and the history. The ratings per sport are on the same page.
 */
class CardsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $cards = VenueCard::where('user_id', $user->id)->with('venue:id,slug,name')->latest('updated_at')->get();

        return Inertia::render('player/Cards', [
            'cards' => $cards->map(fn (VenueCard $c) => [
                'id' => $c->id, 'venue' => ['slug' => $c->venue->slug, 'name' => $c->venue->name], 'balance' => $c->balance,
                'discount' => $c->discount_pct, 'qr' => Qr::svg($c->qrUrl()), 'code' => $c->code,
                'history' => CardEntry::where('card_id', $c->id)->latest('id')->limit(15)->get()
                    ->map(fn (CardEntry $e) => ['id' => $e->id, 'amount' => $e->amount, 'kind' => $e->kind, 'note' => $e->note,
                        'at' => $e->created_at->setTimezone(LocalTime::tz())->format('d.m H:i')])->values(),
            ])->values(),
            'ratings' => Ratings::of($user),
            'open' => $request->query('venue'),
        ]);
    }

    /** "My card" on a club page: made on the first tap, then shown. */
    public function store(Request $request, Venue $venue, Cards $cards)
    {
        try {
            $cards->forPlayer($venue, $request->user());
        } catch (\App\Domain\Booking\BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('me.cards', ['venue' => $venue->slug]);
    }
}
