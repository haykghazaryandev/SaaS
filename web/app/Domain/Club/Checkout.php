<?php

namespace App\Domain\Club;

use App\Domain\Booking\BookingException;
use App\Domain\Venues\PriceCalculator;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\VenueCard;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The desk of a club: a game's check while they play (time so far + what they took), "Stop" when they finish
 * (the time charged is fixed), then the payment: from the player's card (we know it from the booking: no scan
 * needed to pay), in cash, or part each (1 450 + 50). Topping a card up is what needs the QR, not paying a bill.
 *
 * Time charged: rounded up to the place's step (15 min), at least the booked length (playing longer costs more).
 */
class Checkout
{
    public function __construct(private Money $money, private PriceCalculator $prices) {}

    // ---------- items on the check ----------

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

    // ---------- start / stop at the desk ----------

    /** They finished: the time charged is fixed and the table's time follows it (shorter frees it, longer takes more if free). */
    public function stop(Booking $booking, User $by): Booking
    {
        $this->ensureOpen($booking);
        if ($booking->stopped_at) {
            throw new BookingException('already_stopped');
        }
        if ($booking->starts_at->isFuture()) {
            throw new BookingException('not_started');
        }
        $minutes = $this->chargedMinutes($booking, LocalTime::now());
        $price = $this->timePrice($booking, $minutes)['price'];
        $end = $booking->starts_at->toImmutable()->addMinutes($minutes);
        try {
            DB::transaction(fn () => $booking->update(['ends_at' => $end->utc()]));
        } catch (QueryException $e) {
            // longer than booked, but the next booking is there: the table keeps its time, the price still counts all of it
            if (($e->errorInfo[0] ?? null) !== '23P01') {
                throw $e;
            }
        }
        $booking->update(['price' => $price, 'stopped_at' => now()]);

        return $booking;
    }

    // ---------- the check ----------

    /**
     * The check: time (full price, the player's discount, what it costs) + items (their discount too) = total,
     * and the player's card here (if they have one). While they still play: as if stopped now.
     */
    public function bill(Booking $booking): array
    {
        $booking->loadMissing('items', 'venue', 'field');
        $card = $this->card($booking);
        $pct = $this->discountPct($booking);
        $running = ! $booking->stopped_at && ! $booking->paid_at && $booking->starts_at->isPast();
        $minutes = $running ? $this->chargedMinutes($booking, LocalTime::now()) : $booking->durationMinutes();
        $time = $running ? $this->timePrice($booking, $minutes) : ['full' => $this->fullPrice($booking, $minutes), 'price' => (int) $booking->price];
        $items = (int) $booking->items->sum(fn (BookingItem $i) => $i->qty * $i->price_each);
        $itemsOff = (int) round($items * $pct / 100);

        // while playing: until when the price stays, and what each next step adds ("then +1 000 ֏ per 15 min")
        $step = $this->step($booking);
        $next = $running ? [
            'until' => $booking->starts_at->toImmutable()->addMinutes($minutes)->setTimezone(LocalTime::tz())->format('H:i'),
            'step' => $step,
            'adds' => $this->timePrice($booking, $minutes + $step)['price'] - $time['price'],
        ] : null;

        return [
            'minutes' => $minutes,
            'running' => $running,
            'next' => $next,
            'stopped' => $booking->stopped_at !== null,
            'timeFull' => max($time['full'], $time['price']),
            'timeDiscount' => max(0, $time['full'] - $time['price']),
            'time' => $time['price'],
            'items' => $booking->items->map(fn (BookingItem $i) => ['id' => $i->id, 'name' => $i->name, 'qty' => $i->qty, 'price_each' => $i->price_each, 'sum' => $i->qty * $i->price_each])->values()->all(),
            'itemsTotal' => $items,
            'itemsDiscount' => $itemsOff,
            'discountPct' => $pct,
            'total' => $time['price'] + $items - $itemsOff,
            'card' => $card ? ['code' => $card->code, 'balance' => $card->balance] : null,
            'paid' => $booking->paid_at !== null,
        ];
    }

    /** The card that pays: the booking's player's card here. */
    public function card(Booking $booking): ?VenueCard
    {
        return $booking->user_id && $booking->venue?->hasFeature('wallet')
            ? VenueCard::where('venue_id', $booking->venue_id)->where('user_id', $booking->user_id)->first() : null;
    }

    /** A card scanned for a booking made by phone without an account: the booking gets the card's player. */
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
    }

    /** Pays the check: $card from the player's card, $cash the rest; together exactly the total. Stops a running game first. */
    public function pay(Booking $booking, int $card, int $cash, User $by): void
    {
        $this->ensureOpen($booking);
        if (! $booking->stopped_at && $booking->starts_at->isPast()) {
            $this->stop($booking, $by);
            $booking->refresh();
        }
        $bill = $this->bill($booking);
        if ($card < 0 || $cash < 0 || $card + $cash !== $bill['total']) {
            throw new BookingException('bad_amount');
        }
        if ($card > 0 && ! $bill['card']) {
            throw new BookingException('no_card');
        }

        DB::transaction(function () use ($booking, $bill, $card, $by) {
            $booking->loadMissing('user', 'venue', 'items');
            $timeKind = $booking->purpose === 'training' ? 'training' : 'booking';
            $shop = $bill['itemsTotal'] - $bill['itemsDiscount'];
            // the card pays the time first, then the items; cash pays what is left of each (the report keeps them apart)
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

    // ---------- time ----------

    /** Minutes charged if they stop at $now: played, rounded up to the step; a booked game at least its booked length. */
    public function chargedMinutes(Booking $booking, CarbonImmutable $now): int
    {
        $step = $this->step($booking);
        $played = max(0, (int) ceil(($now->getTimestamp() - $booking->starts_at->getTimestamp()) / 60));
        $played = max($step, (int) (ceil($played / $step) * $step));

        return $booking->open_end ? $played : max($played, $booking->durationMinutes());
    }

    /** ['full' => the price list for these minutes, 'price' => after the player's discount]. */
    private function timePrice(Booking $booking, int $minutes): array
    {
        $full = $this->fullPrice($booking, $minutes);

        return ['full' => $full, 'price' => (int) round($full * (100 - $this->discountPct($booking)) / 100)];
    }

    private function fullPrice(Booking $booking, int $minutes): int
    {
        return $this->prices->price($booking->field, $booking->starts_at->toImmutable(), $minutes, $booking->purpose ?? 'play');
    }

    private function discountPct(Booking $booking): int
    {
        return $booking->venue?->hasFeature('wallet') ? Cards::discountFor($booking->venue_id, $booking->user_id) : 0;
    }

    private function step(Booking $booking): int
    {
        return max(5, (int) $booking->venue->rule('step_min'));
    }

    private function ensureOpen(Booking $booking): void
    {
        if ($booking->paid_at !== null) {
            throw new BookingException('already_paid');
        }
    }
}
