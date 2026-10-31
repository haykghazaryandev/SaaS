<?php

namespace App\Http\Controllers\Owner\Club;

use App\Domain\Booking\AfterBooking\Attendance;
use App\Domain\Club\Checkout;
use App\Domain\Venues\Availability;
use App\Domain\Venues\PriceCalculator;
use App\Http\Controllers\Owner\BookingPresenter;
use App\Http\Controllers\Owner\OwnerController;
use App\Models\Booking;
use App\Models\Field;
use App\Models\Product;
use App\Support\LocalTime;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;

/**
 * Owner → Now: the desk of a club, a big card per table. A game: its time counting down to its end (then "time is up":
 * the bill does not grow; take the money or add time), the check (time + items), the next booking; "Add time" offers
 * only what fits before the next booking or the closing, each with what it adds to the bill. A booked team due: Start
 * game / Didn't come. A free table: for how long, and a new game of a length that fits, with its price.
 */
class NowController extends OwnerController
{
    public function index(Request $request, Checkout $checkout, Availability $availability, PriceCalculator $prices)
    {
        $venue = $this->venue($request);
        $this->ensureAny($venue, ['desk']);
        $venue->load('fields', 'openingHours');
        $now = LocalTime::now();
        $tz = LocalTime::tz();
        $hm = fn ($t) => $t->setTimezone($tz)->format('H:i');
        // the closing time today: free tables are "free until" the next booking or the closing
        $hours = $availability->hours($venue, $now->startOfDay());
        $close = $hours ? LocalTime::at($now->startOfDay(), $hours[1]) : $now->startOfDay()->addDay();
        $bookings = Booking::where('venue_id', $venue->id)->whereIn('status', ['pending', 'accepted', 'completed'])
            ->where('ends_at', '>', $now->subHours(12)->utc())->where('starts_at', '<', $now->startOfDay()->addDay()->utc())
            ->with('user', 'field', 'items', 'venue')->orderBy('starts_at')->get();
        $minsTo = fn ($t) => max(0, (int) round(($t->getTimestamp() - $now->getTimestamp()) / 60));

        $courts = $venue->fields->where('active', true)->sortBy(fn (Field $f) => [$f->sort, $f->id])->values()->map(function (Field $f) use ($bookings, $checkout, $now, $close, $hm, $minsTo, $prices, $venue) {
            $f->setRelation('venue', $venue);
            $mine = $bookings->where('field_id', $f->id);
            // the games of this table still open at the desk: playing (paid before or not), or over and not paid yet
            $over = fn (Booking $b) => $b->stopped_at || ! $b->ends_at->isFuture();
            $open = $mine->filter(fn (Booking $b) => $b->started_at && ! ($b->paid_at && $over($b)));
            $live = $open->first(fn (Booking $b) => ! $over($b));
            $unpaid = $open->filter(fn (Booking $b) => ! $b->paid_at && $over($b))->values();
            // a booked team due now (from 30 min before until its end) that has not started: Start game / Didn't come
            $dueNow = $mine->first(fn (Booking $b) => ! $b->started_at && $b->status->value !== 'pending'
                && $b->starts_at->lessThanOrEqualTo($now->addMinutes(Checkout::EARLY_MINUTES)) && $b->ends_at->isFuture());
            // the table shows what is playing; else the last game over and not paid (red) — until the next booked team's
            // time has come: then the team (Start game), and the unpaid game waits for its money under "To pay"
            $old = $live ? null : $unpaid->last();
            $game = $live ?: ($old && ! ($dueNow && $dueNow->starts_at->lessThanOrEqualTo($now)) ? $old : null);
            $due = $game ? null : $dueNow;
            $toPay = $unpaid->reject(fn (Booking $b) => $b->id === $game?->id)->values();
            $gameOver = $game && $over($game);
            $next = $mine->first(fn (Booking $b) => ! $b->started_at && $b->starts_at->greaterThan($now) && $b->id !== $due?->id);
            // free time: after the game (or now) until the next booking or the closing; a time still held now (a game
            // paid but its time not over, a request for now) counts as taken
            // (a stopped game is over: at most the minute it stopped in)
            $held = $mine->filter(fn (Booking $b) => in_array($b->status->value, ['pending', 'accepted'], true) && ! $b->stopped_at
                && $b->starts_at->lessThanOrEqualTo($now) && $b->ends_at->greaterThan($now))
                ->max(fn (Booking $b) => $b->ends_at->getTimestamp());
            $from = $game && ! $gameOver ? $game->ends_at->toImmutable() : ($due ? $due->ends_at->toImmutable() : $now);
            if (! $game && ! $due && $held) {
                $from = CarbonImmutable::createFromTimestamp($held, LocalTime::tz());
            }
            $until = $next ? $next->starts_at->toImmutable() : $close;
            $gap = max(0, (int) round(($until->getTimestamp() - max($from->getTimestamp(), $now->getTimestamp())) / 60));
            // what stops more time: the next booking or the closing
            $limit = ['by' => $next ? 'next' : 'close', 'at' => $hm($until)];

            return [
                'id' => $f->id, 'label' => BookingPresenter::fieldLabel($f), 'size' => $f->size->value,
                'state' => $game ? ($gameOver ? 'to_pay' : 'playing') : ($due ? 'booked' : 'free'),
                'game' => $game ? [
                    'id' => $game->id, 'name' => $game->team_name ?: BookingPresenter::contactName($game), 'purpose' => $game->purpose,
                    'player' => $game->user ? ['id' => $game->user->id, 'name' => $game->user->name, 'phone' => $game->user->phone ? Phone::format($game->user->phone) : null] : null,
                    'startedAt' => $game->started_at->getTimestamp(),
                    'endsAt' => $game->ends_at->getTimestamp(),
                    'stoppedAt' => $game->stopped_at ? $hm($game->stopped_at) : null,
                    'start' => $hm($game->started_at),
                    'bookedEnd' => $game->open_end ? null : $hm($game->ends_at),
                    'holdsUntil' => $hm($game->ends_at),
                    'openEnd' => (bool) $game->open_end,
                    // paid before the end: still playing, ends by itself when its time is up
                    'paid' => $game->paid_at !== null,
                    // how many minutes the game may still be held for (the next booking is never moved)
                    'canExtend' => $game->stopped_at ? 0 : min(120, $gap),
                    // Add time: what each choice adds to the bill (open-ended games pay what they play)
                    'addPrices' => $game->stopped_at || $game->open_end ? (object) [] : collect([5, 15, 30, 60])->mapWithKeys(fn ($m) => [$m => $checkout->addPrice($game, $m)]),
                    'limit' => $limit,
                    'bill' => $checkout->bill($game),
                ] : null,
                // booked, due now, not started yet (late: minutes since its start)
                'due' => $due ? ['id' => $due->id, 'name' => $due->team_name ?: BookingPresenter::contactName($due), 'start' => $hm($due->starts_at), 'end' => $hm($due->ends_at),
                    'in' => $minsTo($due->starts_at), 'late' => $due->starts_at->isPast() ? (int) $due->starts_at->diffInMinutes($now) : 0,
                    'phone' => $due->contact_phone ? Phone::format($due->contact_phone) : null,
                    'canNoShow' => Attendance::canNoShow($due)] : null,
                'next' => $next ? ['id' => $next->id, 'start' => $hm($next->starts_at), 'in' => $minsTo($next->starts_at), 'name' => $next->team_name ?: BookingPresenter::contactName($next), 'pending' => $next->status->value === 'pending'] : null,
                // games over and not paid, the table already has the next one: their money is still to take
                'toPay' => $toPay->map(fn (Booking $b) => $this->unpaidRow($b, $checkout, $hm))->values(),
                // a free table (or one whose last game is over, waiting only for its money): free for how long
                'freeMin' => ($game && ! $gameOver) || $due ? null : $gap,
                'freeFrom' => ! $game && ! $due && $held ? $hm($from) : null,
                'freeUntil' => $game || $due ? null : ($next ? $hm($next->starts_at) : $hm($close)),
                'limit' => $limit,
                // New game: the lengths with their price now (the place's price list)
                'walkPrices' => ($game && ! $gameOver) || $due ? [] : collect([15, 30, 45, 60, 90, 120])->map(fn ($m) => ['min' => $m, 'price' => $prices->price($f, $now, $m), 'fits' => $m <= $gap])->values(),
                // the same lengths as a training, at the training price (only where this kind has one)
                'trainPrices' => ($game && ! $gameOver) || $due || $venue->trainingPrice($f->size->value) === null ? [] : collect([15, 30, 45, 60, 90, 120])
                    ->map(fn ($m) => ['min' => $m, 'price' => $prices->price($f, $now, $m, 'training'), 'fits' => $m <= $gap])->values(),
            ];
        });

        return Inertia::render('owner/Now', [
            'canDemo' => ! app()->isProduction(),
            'courts' => $courts,
            // every game whose money is still to take while its table moved on (the "To pay" list)
            'toPay' => $courts->flatMap(fn ($c) => collect($c['toPay'])->map(fn ($r) => $r + ['field' => $c['label']]))->values(),
            'wallet' => $venue->hasFeature('wallet'),
            'step' => max(5, (int) $venue->rule('step_min')),
            'products' => $venue->hasFeature('shop') ? Product::where('venue_id', $venue->id)->where('active', true)->where('in_stock', true)->orderBy('sort')->orderBy('name')->get()
                ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->finalPrice(), 'photo' => $p->photoUrl()])->values() : [],
        ]);
    }

    /** A game over and not paid, for the "To pay" list (and the red strip on its table). */
    private function unpaidRow(Booking $b, Checkout $checkout, callable $hm): array
    {
        return ['id' => $b->id, 'name' => $b->team_name ?: BookingPresenter::contactName($b), 'start' => $hm($b->starts_at), 'end' => $hm($b->ends_at),
            'ended' => $hm($b->stopped_at ?? $b->ends_at), 'purpose' => $b->purpose, 'bill' => $checkout->bill($b),
            'player' => $b->user ? ['id' => $b->user->id, 'name' => $b->user->name] : null];
    }

    /** "Start game": the booked team is here. */
    public function start(Request $request, Booking $booking, Checkout $checkout)
    {
        abort_unless($booking->venue_id === $this->venue($request)->id, 404);
        $this->attempt(fn () => $checkout->start($booking, $request->user()));

        return back()->with('success', __('owner.club.started'));
    }

    /** A walk-in on a free table: started now, holding the table for the minutes chosen. */
    public function walkIn(Request $request, Field $field, Checkout $checkout)
    {
        abort_unless($field->venue_id === $this->venue($request)->id, 404);
        $data = $request->validate(['minutes' => 'required|integer|min:5|max:240', 'name' => 'nullable|string|max:60', 'purpose' => 'nullable|in:play,training']);
        $field->setRelation('venue', $this->venue($request));
        $this->attempt(fn () => $checkout->walkIn($field, $request->user(), (int) $data['minutes'], $data['name'] ?? null, $data['purpose'] ?? 'play'));

        return back()->with('success', __('owner.club.started'));
    }

    /** "+15 min" on a game (if the table is free that long). */
    public function extend(Request $request, Booking $booking, Checkout $checkout)
    {
        abort_unless($booking->venue_id === $this->venue($request)->id, 404);
        $data = $request->validate(['minutes' => 'required|integer|min:5|max:240']);
        $this->attempt(fn () => $checkout->extend($booking, (int) $data['minutes'], $request->user()));

        return back()->with('success', __('owner.club.extended', ['n' => $data['minutes']]));
    }

    /** Local / staging: test games on this place's tables right now, whatever the hour (never in production). */
    public function demo(Request $request)
    {
        abort_if(app()->isProduction(), 404);
        $this->ensureOwner($request);
        Artisan::call('club:demo-now', ['venue' => $this->venue($request)->slug]);

        return back()->with('success', __('owner.club.demo_added'));
    }
}
