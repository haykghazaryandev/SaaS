<?php

namespace App\Domain\Club;

use App\Domain\Booking\Actions\OwnerBooking;
use App\Domain\Booking\BookingException;
use App\Domain\Venues\PriceCalculator;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\BookingItem;
use App\Models\Field;
use App\Models\Payment;
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
 * Time charged: the booked length (with the time added; stopping early costs the same). When the time is up the bill
 * does not grow: the desk takes the money or adds time. An open-ended game: the minutes played, rounded up to the step.
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

        // the same product at the same price again: one line with more of it (3 × Cola, not 2 × and 1 ×)
        // (only lines not paid yet: something taken after paying is a new line to pay)
        $same = BookingItem::where('booking_id', $booking->id)->where('product_id', $product->id)->where('price_each', $product->finalPrice())->whereNull('paid_at')->first();
        if ($same && $same->qty + $qty <= 100) {
            $same->update(['qty' => $same->qty + $qty]);
        } else {
            $same = BookingItem::create(['booking_id' => $booking->id, 'product_id' => $product->id, 'name' => $product->name,
                'qty' => $qty, 'price_each' => $product->finalPrice(), 'created_by' => $by->id]);
        }
        $this->owesAgain($booking);

        return $same;
    }

    public function removeItem(BookingItem $item): void
    {
        $this->ensureOpen($item->booking);
        // paid already: sold, it stays on the bill
        if ($item->paid_at) {
            throw new BookingException('already_paid');
        }
        $item->delete();
        $this->settleIfNothingDue($item->booking->fresh());
    }

    // ---------- start / stop at the desk ----------

    /** How early before its time a booked game can be started at the desk. */
    public const EARLY_MINUTES = 30;

    /**
     * "Start game": the team is here and plays. The time is counted from now (at least the booked length), the bill
     * starts. Until then a booking is only planned: no bill, no money. From 30 min before its time until its end.
     */
    public function start(Booking $booking, User $by): Booking
    {
        $this->ensureOpen($booking);
        if ($booking->started_at) {
            throw new BookingException('already_started_game');
        }
        if ($booking->status === BookingStatus::Pending) {
            throw new BookingException('accept_first');
        }
        if (! in_array($booking->status, [BookingStatus::Accepted, BookingStatus::Completed], true)
            || $booking->starts_at->greaterThan(now()->addMinutes(self::EARLY_MINUTES)) || $booking->ends_at->isPast()) {
            throw new BookingException('not_active');
        }
        $booking->update(['started_at' => now(), 'arrived_at' => $booking->arrived_at ?? now()]);
        BookingEvent::create(['booking_id' => $booking->id, 'type' => 'started', 'user_id' => $by->id]);

        return $booking;
    }

    /**
     * A new game at a free table (Now → New game): started now for the $minutes chosen, charged that length like a
     * booked game; it holds the table (players can't book over it) and stopping gives the rest of the time back.
     */
    public function walkIn(Field $field, User $by, int $minutes, ?string $name = null): Booking
    {
        $minutes = max($this->stepOf($field), min(240, $minutes));
        // from this minute (a 15 min game shows 15 min left, not 16); right after a game stopped on this table that
        // still holds the table to the next minute, from the minute it gives back
        $start = LocalTime::now()->setSecond(0)->setMicrosecond(0);
        $held = Booking::query()->where('field_id', $field->id)->whereIn('status', [BookingStatus::Pending, BookingStatus::Accepted, BookingStatus::Completed])
            ->where('ends_at', '>', $start->utc())->where('ends_at', '<=', self::nextMinute()->utc())->max('ends_at');
        if ($held) {
            $start = CarbonImmutable::parse($held)->setTimezone(LocalTime::tz());
        }
        $b = app(OwnerBooking::class)->create($by, $field, $start, $minutes, ['name' => $name ?: __('owner.club.walk_in')]);
        // a game of the length chosen (it counts down like a booked one, at its price-list price)
        $b->update(['started_at' => now(), 'arrived_at' => now()]);

        return $b;
    }

    /** "+15 min": the game holds the table longer, if nobody booked it (the next booking is never moved). */
    public function extend(Booking $booking, int $minutes, User $by): Booking
    {
        $this->ensureOpen($booking);
        if ($minutes < 5 || $minutes > 240 || $booking->stopped_at || ! in_array($booking->status, [BookingStatus::Accepted, BookingStatus::Completed], true)) {
            throw new BookingException('bad_options');
        }
        try {
            DB::transaction(fn () => $booking->update(['ends_at' => $booking->ends_at->toImmutable()->addMinutes($minutes)->utc(), 'status' => BookingStatus::Accepted]));
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23P01') {
                throw new BookingException('taken');
            }
            throw $e;
        }
        BookingEvent::create(['booking_id' => $booking->id, 'type' => 'extended', 'user_id' => $by->id, 'changes' => ['minutes' => $minutes]]);
        $this->owesAgain($booking);

        return $booking;
    }

    /** They finished: the time charged is fixed and the table's time follows it (shorter frees it, longer takes more if free). */
    public function stop(Booking $booking, User $by): Booking
    {
        $this->ensureOpen($booking);
        if ($booking->stopped_at) {
            throw new BookingException('already_stopped');
        }
        if (! $booking->started_at) {
            throw new BookingException('not_started');
        }
        $minutes = $this->chargedMinutes($booking, LocalTime::now());
        $price = $this->timePrice($booking, $minutes)['price'];
        // the table is free from now on (the next full minute): the bill still counts the time charged (rounded up, at
        // least the booked length), but nobody waits for minutes nobody plays
        $end = self::nextMinute();
        $end = $end->lessThanOrEqualTo($booking->starts_at) ? $booking->starts_at->toImmutable()->addMinute() : $end;
        try {
            DB::transaction(fn () => $booking->update(['ends_at' => $end->utc()]));
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) !== '23P01') {
                throw $e;
            }
            // the time after it is booked already (the game ran over into the next one): it keeps its own end;
            // forget the end that failed, or the next save would send it again
            $booking->refresh();
        }
        $booking->update(['price' => $price, 'stopped_at' => now(), 'charged_minutes' => $minutes]);
        $this->settleIfNothingDue($booking->fresh());

        return $booking->fresh();
    }

    // ---------- the check ----------

    /**
     * The check: time (the price list for the minutes played) + items = total,
     * and the player's card here (if they have one). While they still play: as if stopped now.
     */
    public function bill(Booking $booking): array
    {
        $booking->loadMissing('items', 'venue', 'field');
        $card = $this->card($booking);
        // a bill only once the game was started at the desk; before that the booking is just planned
        $started = $booking->started_at !== null;
        // playing until ended (paid before or not)
        $running = $started && ! $booking->stopped_at;
        $minutes = $running ? $this->chargedMinutes($booking, LocalTime::now()) : ($booking->charged_minutes ?? $booking->durationMinutes());
        $time = $running ? $this->timePrice($booking, $minutes) : ['full' => $this->fullPrice($booking, $minutes), 'price' => (int) $booking->price];
        $items = (int) $booking->items->sum(fn (BookingItem $i) => $i->qty * $i->price_each);
        // paid before (they may pay before the end): the time paid so far and the items already sold
        $timePaid = $started ? (int) Payment::where('ref_id', $booking->id)->where('venue_id', $booking->venue_id)
            ->whereIn('kind', ['booking', 'training'])->sum('amount') : 0;
        $itemsDue = (int) $booking->items->whereNull('paid_at')->sum(fn (BookingItem $i) => $i->qty * $i->price_each);
        $timeDue = max(0, $time['price'] - $timePaid);

        return [
            'started' => $started,
            'minutes' => $minutes,
            'running' => $running,
            'stopped' => $booking->stopped_at !== null,
            'time' => $time['price'],
            'items' => $booking->items->map(fn (BookingItem $i) => ['id' => $i->id, 'product_id' => $i->product_id, 'name' => $i->name, 'qty' => $i->qty, 'price_each' => $i->price_each, 'sum' => $i->qty * $i->price_each, 'paid' => $i->paid_at !== null])->values()->all(),
            'itemsTotal' => $items,
            'total' => $time['price'] + $items,
            // the card's cashback on what it pays now (Cards::level)
            'card' => $card ? ['code' => $card->code, 'balance' => $card->balance, 'cashback' => Cards::levelOf($card)['pct']] : null,
            'paid' => $booking->paid_at !== null,
            'paidSoFar' => $timePaid + ($items - $itemsDue),
            'timeDue' => $timeDue,
            'itemsDue' => $itemsDue,
            // what is still to take
            'due' => $timeDue + $itemsDue,
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
    /**
     * Take the money: what is still to pay on the bill (all of it, or what was added after paying before). They may
     * pay before the end: the game goes on, paid; once its time is up it simply ends. A game whose time is up is
     * ended now. Card first for the time, then the items; cash for the rest (the report keeps them apart).
     */
    public function pay(Booking $booking, int $card, int $cash, User $by): void
    {
        $this->ensureOpen($booking);
        if (! $booking->started_at) {
            throw new BookingException('not_started');
        }
        if (! $booking->stopped_at && ! $booking->ends_at->isFuture()) {
            $booking = $this->stop($booking, $by);
        }
        $bill = $this->bill($booking);
        if ($bill['due'] <= 0 && $booking->paid_at) {
            throw new BookingException('already_paid');
        }
        if ($bill['due'] <= 0 || $card < 0 || $cash < 0 || $card + $cash !== $bill['due']) {
            throw new BookingException('bad_amount');
        }
        if ($card > 0 && ! $bill['card']) {
            throw new BookingException('no_card');
        }

        DB::transaction(function () use ($booking, $bill, $card, $by) {
            $booking->loadMissing('user', 'venue', 'items');
            $timeKind = $booking->purpose === 'training' ? 'training' : 'booking';
            $cardTime = min($card, $bill['timeDue']);
            $cardShop = $card - $cardTime;
            $parts = [
                [$timeKind, 'card', $cardTime], [$timeKind, 'cash', $bill['timeDue'] - $cardTime],
                ['shop', 'card', $cardShop], ['shop', 'cash', $bill['itemsDue'] - $cardShop],
            ];
            foreach ($parts as [$kind, $method, $amount]) {
                if ($amount > 0) {
                    $this->money->charge($booking->venue, $by, $kind, $amount, $method, $booking->user, $booking->id, $booking->code, notify: false);
                }
            }
            // the items not paid before are sold now
            foreach ($booking->items->whereNull('paid_at') as $i) {
                Sale::create(['venue_id' => $booking->venue_id, 'product_id' => $i->product_id, 'product_name' => $i->name, 'user_id' => $booking->user_id,
                    'qty' => $i->qty, 'price_each' => $i->price_each, 'created_by' => $by->id]);
                $i->update(['paid_at' => now()]);
            }
            $booking->update(['paid_at' => now()]);
            // one message for what the card paid
            if ($card > 0) {
                Money::tellPaid($booking->venue, $booking->user, $card, (int) $this->card($booking)?->fresh()?->balance);
            }
        });
    }

    /** Something was added after paying (+ time, + item): there is something to pay again. */
    private function owesAgain(Booking $booking): void
    {
        if ($booking->paid_at) {
            $booking->update(['paid_at' => null]);
        }
    }

    /** Paid before, and the change left nothing to pay (an added item removed, the game ended): settled again. */
    private function settleIfNothingDue(Booking $booking): void
    {
        if (! $booking->paid_at && $booking->started_at) {
            $bill = $this->bill($booking);
            if ($bill['paidSoFar'] > 0 && $bill['due'] === 0) {
                $booking->update(['paid_at' => now()]);
            }
        }
    }

    // ---------- time ----------

    /** Minutes charged if they stop at $now: the booked length (time added included); open-ended: played, rounded up to the step. */
    public function chargedMinutes(Booking $booking, CarbonImmutable $now): int
    {
        if (! $booking->open_end) {
            return $booking->durationMinutes();
        }
        $step = $this->step($booking);
        $from = ($booking->started_at ?? $booking->starts_at)->getTimestamp();
        $played = max(0, (int) ceil(($now->getTimestamp() - $from) / 60));

        return max($step, (int) (ceil($played / $step) * $step));
    }

    /** What adding these minutes costs on top of the bill now (Now → Add time: "+15 min · +1 250 ֏"). */
    public function addPrice(Booking $booking, int $minutes): int
    {
        $now = $this->chargedMinutes($booking, LocalTime::now());

        return $this->timePrice($booking, $now + $minutes)['price'] - $this->timePrice($booking, $now)['price'];
    }

    /** ['full' => the price list for these minutes, 'price' => what it costs (the same: no card discounts)]. */
    private function timePrice(Booking $booking, int $minutes): array
    {
        $full = $this->fullPrice($booking, $minutes);

        return ['full' => $full, 'price' => $full];
    }

    private function fullPrice(Booking $booking, int $minutes): int
    {
        return $this->prices->price($booking->field, $booking->starts_at->toImmutable(), $minutes, $booking->purpose ?? 'play');
    }

    private function step(Booking $booking): int
    {
        return max(5, (int) $booking->venue->rule('step_min'));
    }

    /** Now, rounded up to the next full minute (a game stopped and the next one started meet there). */
    public static function nextMinute(): CarbonImmutable
    {
        $now = LocalTime::now();

        return $now->second === 0 && $now->micro === 0 ? $now : $now->setSecond(0)->setMicrosecond(0)->addMinute();
    }

    private function stepOf(Field $field): int
    {
        return max(5, (int) $field->venue->rule('step_min'));
    }

    /** Closed: paid and over (ended, or its time is up). A paid game still playing can get more time or items. */
    private function ensureOpen(Booking $booking): void
    {
        if ($booking->paid_at !== null && ($booking->stopped_at || ! $booking->ends_at->isFuture())) {
            throw new BookingException('already_paid');
        }
    }
}
