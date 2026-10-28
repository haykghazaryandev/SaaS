<?php

namespace App\Http\Controllers\Owner\Club;

use App\Domain\Booking\AfterBooking\Attendance;
use App\Domain\Club\Checkout;
use App\Domain\Venues\Availability;
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
 * Owner → Now: the desk of a club. Every table: the booked game playing on it (who, since when, its check live:
 * time + items, the total, the player's card), a finished game waiting to be paid, or free (and
 * its next booking). Add a cola to the check, "Stop" → the check → card / cash / both. Games come from bookings.
 */
class NowController extends OwnerController
{
    public function index(Request $request, Checkout $checkout, Availability $availability)
    {
        $venue = $this->venue($request);
        $this->ensureAny($venue, ['wallet', 'shop']);
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

        $courts = $venue->fields->where('active', true)->sortBy(fn (Field $f) => [$f->sort, $f->id])->values()->map(function (Field $f) use ($bookings, $checkout, $now, $close, $hm, $minsTo) {
            $mine = $bookings->where('field_id', $f->id);
            // a game: started at the desk and not paid yet (playing, or stopped and waiting for the money)
            $game = $mine->first(fn (Booking $b) => $b->started_at && ! $b->paid_at);
            // a booked team due now (from 30 min before until its end) that has not started: Start game / Didn't come
            $due = $game ? null : $mine->first(fn (Booking $b) => ! $b->started_at && $b->status->value !== 'pending'
                && $b->starts_at->lessThanOrEqualTo($now->addMinutes(Checkout::EARLY_MINUTES)) && $b->ends_at->isFuture());
            $next = $mine->first(fn (Booking $b) => ! $b->started_at && $b->starts_at->greaterThan($now) && $b->id !== $due?->id);
            // free time: after the game (or now) until the next booking or the closing; a time still held now (a game
            // paid but its time not over, a request for now) counts as taken
            // (a stopped game is over: at most the minute it stopped in)
            $held = $mine->filter(fn (Booking $b) => in_array($b->status->value, ['pending', 'accepted'], true) && ! $b->stopped_at
                && $b->starts_at->lessThanOrEqualTo($now) && $b->ends_at->greaterThan($now))
                ->max(fn (Booking $b) => $b->ends_at->getTimestamp());
            $from = $game && ! $game->stopped_at ? $game->ends_at->toImmutable() : ($due ? $due->ends_at->toImmutable() : $now);
            if (! $game && ! $due && $held) {
                $from = CarbonImmutable::createFromTimestamp($held, LocalTime::tz());
            }
            $until = $next ? $next->starts_at->toImmutable() : $close;
            $gap = max(0, (int) round(($until->getTimestamp() - max($from->getTimestamp(), $now->getTimestamp())) / 60));

            return [
                'id' => $f->id, 'label' => BookingPresenter::fieldLabel($f), 'size' => $f->size->value,
                'state' => $game ? ($game->stopped_at ? 'to_pay' : 'playing') : ($due ? 'booked' : 'free'),
                'game' => $game ? [
                    'id' => $game->id, 'name' => $game->team_name ?: $game->contact_name, 'purpose' => $game->purpose,
                    'player' => $game->user ? ['id' => $game->user->id, 'name' => $game->user->name, 'phone' => $game->user->phone ? Phone::format($game->user->phone) : null] : null,
                    'startedAt' => $game->started_at->getTimestamp(),
                    'start' => $hm($game->started_at),
                    'bookedEnd' => $game->open_end ? null : $hm($game->ends_at),
                    'holdsUntil' => $hm($game->ends_at),
                    'openEnd' => (bool) $game->open_end,
                    // how many minutes the game may still be held for (the next booking is never moved)
                    'canExtend' => $game->stopped_at ? 0 : min(120, $gap),
                    'bill' => $checkout->bill($game),
                ] : null,
                // booked, due now, not started yet (late: minutes since its start)
                'due' => $due ? ['id' => $due->id, 'name' => $due->team_name ?: $due->contact_name, 'start' => $hm($due->starts_at), 'end' => $hm($due->ends_at),
                    'in' => $minsTo($due->starts_at), 'late' => $due->starts_at->isPast() ? (int) $due->starts_at->diffInMinutes($now) : 0,
                    'phone' => $due->contact_phone ? Phone::format($due->contact_phone) : null,
                    'canNoShow' => Attendance::canNoShow($due)] : null,
                'next' => $next ? ['id' => $next->id, 'start' => $hm($next->starts_at), 'in' => $minsTo($next->starts_at), 'name' => $next->team_name ?: $next->contact_name, 'pending' => $next->status->value === 'pending'] : null,
                // a free table: free for how long (a walk-in can play that long)
                'freeMin' => $game || $due ? null : $gap,
                'freeFrom' => ! $game && ! $due && $held ? $hm($from) : null,
                'freeUntil' => $game || $due ? null : ($next ? $hm($next->starts_at) : $hm($close)),
            ];
        });

        return Inertia::render('owner/Now', [
            'canDemo' => ! app()->isProduction(),
            'courts' => $courts,
            'step' => max(5, (int) $venue->rule('step_min')),
            'products' => $venue->hasFeature('shop') ? Product::where('venue_id', $venue->id)->where('active', true)->where('in_stock', true)->orderBy('sort')->orderBy('name')->get()
                ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->finalPrice(), 'photo' => $p->photoUrl()])->values() : [],
        ]);
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
        $data = $request->validate(['minutes' => 'required|integer|min:5|max:240', 'name' => 'nullable|string|max:60']);
        $field->setRelation('venue', $this->venue($request));
        $this->attempt(fn () => $checkout->walkIn($field, $request->user(), (int) $data['minutes'], $data['name'] ?? null));

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
