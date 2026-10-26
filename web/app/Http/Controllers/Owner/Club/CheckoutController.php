<?php

namespace App\Http\Controllers\Owner\Club;

use App\Domain\Booking\BookingException;
use App\Domain\Club\Cards;
use App\Domain\Club\Checkout;
use App\Http\Controllers\Owner\BookingPresenter;
use App\Http\Controllers\Owner\OwnerController;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Product;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A booking's bill at the desk: add what they took during the game, then take the money for time + items:
 * from the player's card (scan it first), in cash, or part each.
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

        return back()->with('success', __('owner.club.card_ok'));
    }

    public function pay(Request $request, Booking $booking, Checkout $checkout)
    {
        $this->ensureSameVenue($request, $booking);
        $data = $request->validate(['card' => 'required|integer|min:0', 'cash' => 'required|integer|min:0']);
        $this->attempt(fn () => $checkout->pay($booking, (int) $data['card'], (int) $data['cash'], $request->user()));

        return redirect()->route('owner.schedule', ['date' => $booking->starts_at->setTimezone(\App\Support\LocalTime::tz())->toDateString()])
            ->with('success', __('owner.club.paid'));
    }
}
