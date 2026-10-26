<?php

namespace App\Domain\Club;

use App\Domain\Booking\BookingException;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\VenueCard;
use Illuminate\Support\Facades\DB;

/**
 * A booking's bill: the playing time (its price, discount already in) and what was sold during the game (a cola),
 * paid at the end in one go: from the player's card (only after scanning it), in cash, or part each (1 450 + 50).
 * Card payments come first off the time, then off the items, so the money report keeps bookings and shop apart.
 */
class Checkout
{
    public function __construct(private Money $money) {}

    public function addItem(Booking $booking, Product $product, int $qty, User $by): BookingItem
    {
        $this->ensureOpen($booking);
        if ($product->venue_id !== $booking->venue_id || $qty < 1 || $qty > 100) {
            throw new BookingException('bad_amount');
        }
        if (! $product->in_stock) {
            throw new BookingException('out_of_stock');
        }

        return BookingItem::create(['booking_id' => $booking->id, 'product_id' => $product->id, 'name' => $product->name,
            'qty' => $qty, 'price_each' => $product->finalPrice(), 'created_by' => $by->id]);
    }

    public function removeItem(BookingItem $item): void
    {
        $this->ensureOpen($item->booking);
        $item->delete();
    }

    /** The card that pays: the booking's player's card here, or one scanned at the desk (it joins the booking). */
    public function card(Booking $booking): ?VenueCard
    {
        return $booking->user_id ? VenueCard::where('venue_id', $booking->venue_id)->where('user_id', $booking->user_id)->first() : null;
    }

    /** A scanned card for this booking: a booking made by phone without an account gets the card's player. */
    public function attachCard(Booking $booking, VenueCard $card): void
    {
        $this->ensureOpen($booking);
        if ($card->venue_id !== $booking->venue_id) {
            throw new BookingException('other_venue');
        }
        if ($booking->user_id === null) {
            $booking->update(['user_id' => $card->user_id]);
        } elseif ($booking->user_id !== $card->user_id) {
            throw new BookingException('other_card');
        }
        Cards::markScanned($card);
    }

    public function bill(Booking $booking): array
    {
        $booking->loadMissing('items', 'venue');
        $card = $this->card($booking);
        $pct = $card && $booking->venue->hasFeature('wallet') ? $card->discount_pct : 0;
        $items = (int) $booking->items->sum(fn (BookingItem $i) => $i->qty * $i->price_each);
        $itemsOff = (int) round($items * $pct / 100);

        return [
            'time' => (int) $booking->price,
            'items' => $booking->items->map(fn (BookingItem $i) => ['id' => $i->id, 'name' => $i->name, 'qty' => $i->qty, 'price_each' => $i->price_each, 'sum' => $i->qty * $i->price_each])->values()->all(),
            'itemsTotal' => $items,
            'itemsDiscount' => $itemsOff,
            'discountPct' => $pct,
            'total' => (int) $booking->price + $items - $itemsOff,
            'card' => $card ? ['code' => $card->code, 'balance' => $card->balance, 'scanned' => Cards::scanned($card)] : null,
            'paid' => $booking->paid_at !== null,
        ];
    }

    /** Pays the bill: $card from the player's card (must be scanned), $cash the rest. Together exactly the total. */
    public function pay(Booking $booking, int $card, int $cash, User $by): void
    {
        $this->ensureOpen($booking);
        $bill = $this->bill($booking);
        if ($card < 0 || $cash < 0 || $card + $cash !== $bill['total']) {
            throw new BookingException('bad_amount');
        }
        $cardRow = $this->card($booking);
        if ($card > 0 && (! $cardRow || ! Cards::scanned($cardRow))) {
            throw new BookingException('scan_first');
        }

        DB::transaction(function () use ($booking, $bill, $card, $cash, $by) {
            $booking->loadMissing('user', 'venue', 'items');
            $timeKind = $booking->purpose === 'training' ? 'training' : 'booking';
            $shop = $bill['itemsTotal'] - $bill['itemsDiscount'];
            // the card pays the time first, then the items; cash pays what is left of each
            $cardTime = min($card, $bill['time']);
            $cardShop = $card - $cardTime;
            $parts = [
                [$timeKind, 'card', $cardTime], [$timeKind, 'cash', $bill['time'] - $cardTime],
                ['shop', 'card', $cardShop], ['shop', 'cash', $shop - $cardShop],
            ];
            foreach ($parts as [$kind, $method, $amount]) {
                if ($amount > 0) {
                    $this->money->charge($booking->venue, $by, $kind, $amount, $method, $booking->user, $booking->id, $booking->code, discount: false);
                }
            }
            foreach ($booking->items as $i) {
                Sale::create(['venue_id' => $booking->venue_id, 'product_id' => $i->product_id, 'product_name' => $i->name, 'user_id' => $booking->user_id,
                    'qty' => $i->qty, 'price_each' => $i->price_each, 'created_by' => $by->id]);
            }
            $booking->update(['paid_at' => now()]);
        });
    }

    private function ensureOpen(Booking $booking): void
    {
        if ($booking->paid_at !== null) {
            throw new BookingException('already_paid');
        }
    }
}
