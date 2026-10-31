<?php

namespace App\Http\Controllers\Owner\Club;

use App\Domain\Club\Cards;
use App\Domain\Club\Checkout;
use App\Http\Controllers\Owner\BookingPresenter;
use App\Http\Controllers\Owner\OwnerController;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Product;
use App\Models\VenueCard;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A booking's check at the desk: what they took during the game, "Stop" when they finish, then the money for
 * time + items: from the player's card (known from the booking), in cash, or part each.
 */
class CheckoutController extends OwnerController
{
    public function show(Request $request, Booking $booking, Checkout $checkout)
    {
        $this->ensureSameVenue($request, $booking);
        $venue = $this->venue($request);
        $booking->load('field', 'user');

        return Inertia::render('owner/Checkout', [
            'booking' => [...BookingPresenter::row($booking), 'field' => BookingPresenter::fieldLabel($booking->field),
                'player' => $booking->user ? ['id' => $booking->user->id, 'name' => $booking->user->name, 'phone' => $booking->user->phone ? Phone::format($booking->user->phone) : null] : null],
            'bill' => $checkout->bill($booking),
            'wallet' => $venue->hasFeature('wallet'),
            'products' => $venue->hasFeature('shop') ? Product::where('venue_id', $venue->id)->where('active', true)->where('in_stock', true)->orderBy('sort')->orderBy('name')->get()
                ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->finalPrice(), 'photo' => $p->photoUrl()])->values() : [],
        ]);
    }

    public function addItem(Request $request, Booking $booking, Checkout $checkout)
    {
        $this->ensureSameVenue($request, $booking);
        $data = $request->validate(['product_id' => 'required|integer', 'qty' => 'nullable|integer|min:1|max:100']);
        $product = Product::where('venue_id', $booking->venue_id)->findOrFail($data['product_id']);
        $this->attempt(fn () => $checkout->addItem($booking, $product, (int) ($data['qty'] ?? 1), $request->user()));

        return back();
    }

    public function removeItem(Request $request, BookingItem $item, Checkout $checkout)
    {
        $this->ensureSameVenue($request, $item->booking);
        $this->attempt(fn () => $checkout->removeItem($item));

        return back();
    }

    /** The card was scanned at the desk for this bill (also gives a phone-only booking its player). */
    public function scan(Request $request, Booking $booking, Checkout $checkout)
    {
        $this->ensureSameVenue($request, $booking);
        $data = $request->validate(['code' => 'required|string|max:200']);
        preg_match('/([a-z0-9]{20})/', strtolower($data['code']), $m);
        $card = isset($m[1]) ? app(Cards::class)->byCode($this->venue($request), $m[1]) : null;
        if (! $card) {
            throw ValidationException::withMessages(['booking' => __('owner.club.not_here')]);
        }
        $this->attempt(fn () => $checkout->attachCard($booking, $card));

        // from the card page (the phone scanned the card first): go on to the bill
        return ($request->boolean('open') ? redirect()->route('owner.bookings.checkout', $booking->id) : back())->with('success', __('owner.club.card_ok'));
    }

    /** No camera at the desk: find the player's card by name or phone, for this bill. */
    public function cards(Request $request, Booking $booking)
    {
        $this->ensureSameVenue($request, $booking);
        $q = trim((string) $request->query('q', ''));
        $digits = preg_replace('/\D/', '', $q);
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }
        $cards = VenueCard::where('venue_id', $booking->venue_id)->with('user:id,name,phone')
            ->whereHas('user', fn ($u) => $u->where('name', 'ilike', '%'.$q.'%')
                ->when(strlen($digits) >= 3, fn ($x) => $x->orWhere('phone', 'like', '%'.$digits.'%')))
            ->limit(8)->get();

        return response()->json($cards->map(fn (VenueCard $c) => ['code' => $c->code, 'name' => $c->user?->name,
            'phone' => $c->user?->phone ? Phone::format($c->user->phone) : null, 'balance' => $c->balance])->values());
    }

    /** They finished: fix the time and show the check. */
    public function stop(Request $request, Booking $booking, Checkout $checkout)
    {
        $this->ensureSameVenue($request, $booking);
        $this->attempt(fn () => $checkout->stop($booking, $request->user()));

        // End game on Now stays on Now; the bill page stays on the bill
        return back()->with('success', __('owner.club.ended'));
    }

    public function pay(Request $request, Booking $booking, Checkout $checkout)
    {
        $this->ensureSameVenue($request, $booking);
        $data = $request->validate(['card' => 'required|integer|min:0', 'cash' => 'required|integer|min:0']);
        $this->attempt(fn () => $checkout->pay($booking, (int) $data['card'], (int) $data['cash'], $request->user()));

        // the Take money window on Now (or the bill page) stays where it was
        return back()->with('success', __('owner.club.paid'));
    }
}
