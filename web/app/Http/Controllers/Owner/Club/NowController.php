<?php

namespace App\Http\Controllers\Owner\Club;

use App\Domain\Club\Checkout;
use App\Http\Controllers\Owner\BookingPresenter;
use App\Http\Controllers\Owner\OwnerController;
use App\Models\Booking;
use App\Models\Field;
use App\Models\Product;
use App\Support\LocalTime;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Owner → Now: the desk of a club. Every table: a game on it (who, since when, its check live: time + items,
 * the discount, the total, the player's card), a finished game waiting to be paid, or free ("Start now" for a
 * walk-in, and the next booking). Add a cola to the check, "Stop" → the check → card / cash / both.
 */
class NowController extends OwnerController
{
    public function index(Request $request, Checkout $checkout)
    {
        $venue = $this->venue($request);
        $this->ensureAny($venue, ['wallet', 'shop']);
        $venue->load('fields');
        $today = LocalTime::now()->startOfDay();
        $bookings = Booking::where('venue_id', $venue->id)->whereIn('status', ['pending', 'accepted', 'completed'])
            ->where('starts_at', '>=', $today->utc())->where('starts_at', '<', $today->addDay()->utc())
            ->with('user', 'field', 'items', 'venue')->orderBy('starts_at')->get();

        $courts = $venue->fields->where('active', true)->sortBy(fn (Field $f) => [$f->sort, $f->id])->values()->map(function (Field $f) use ($bookings, $checkout) {
            $mine = $bookings->where('field_id', $f->id);
            // a game: started today, not paid (still playing, or stopped and waiting for the money)
            $game = $mine->first(fn (Booking $b) => $b->status->value !== 'pending' && $b->starts_at->isPast() && ! $b->paid_at
                && ($b->stopped_at || $b->ends_at->isFuture() || $b->open_end || $b->ends_at->greaterThan(now()->subHours(3))));
            $next = $mine->first(fn (Booking $b) => $b->starts_at->isFuture() && $b->id !== $game?->id);

            return [
                'id' => $f->id, 'label' => BookingPresenter::fieldLabel($f), 'size' => $f->size->value,
                'state' => $game ? ($game->stopped_at ? 'to_pay' : 'playing') : 'free',
                'game' => $game ? [
                    'id' => $game->id, 'name' => $game->team_name ?: $game->contact_name, 'purpose' => $game->purpose,
                    'player' => $game->user ? ['id' => $game->user->id, 'name' => $game->user->name, 'phone' => $game->user->phone ? Phone::format($game->user->phone) : null] : null,
                    'startedAt' => $game->starts_at->getTimestamp(),
                    'start' => $game->starts_at->setTimezone(LocalTime::tz())->format('H:i'),
                    'bookedEnd' => $game->open_end ? null : $game->ends_at->setTimezone(LocalTime::tz())->format('H:i'),
                    'bill' => $checkout->bill($game),
                ] : null,
                'next' => $next ? ['start' => $next->starts_at->setTimezone(LocalTime::tz())->format('H:i'), 'name' => $next->team_name ?: $next->contact_name, 'pending' => $next->status->value === 'pending'] : null,
            ];
        });

        return Inertia::render('owner/Now', [
            'courts' => $courts,
            'products' => $venue->hasFeature('shop') ? Product::where('venue_id', $venue->id)->where('active', true)->where('in_stock', true)->orderBy('sort')->orderBy('name')->get()
                ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->finalPrice()])->values() : [],
            'trainings' => collect($venue->rules['training_prices'] ?? [])->filter()->isNotEmpty() && $venue->hasFeature('trainings'),
        ]);
    }

    /** A walk-in on a free table, from now until they stop. */
    public function start(Request $request, Checkout $checkout)
    {
        $venue = $this->venue($request);
        $data = $request->validate([
            'field_id' => ['required', 'integer', Rule::exists('fields', 'id')->where('venue_id', $venue->id)],
            'name' => 'nullable|string|max:80', 'phone' => 'nullable|string|max:20', 'purpose' => 'nullable|in:play,training',
        ]);
        if (filled($data['phone'] ?? null) && ! Phone::normalize($data['phone'])) {
            return back()->withErrors(['phone' => __('owner.form.bad_phone')]);
        }
        $field = Field::findOrFail($data['field_id']);
        $this->attempt(fn () => $checkout->startWalkIn($field, $request->user(), $data));

        return back()->with('success', __('owner.club.started'));
    }
}
