<?php

namespace App\Http\Controllers\Owner\Club;

use App\Domain\Club\Cards;
use App\Domain\Club\Money;
use App\Domain\Club\Ratings;
use App\Domain\Club\Shop;
use App\Domain\Club\Trainings;
use App\Enums\Sport;
use App\Http\Controllers\Owner\BookingPresenter;
use App\Http\Controllers\Owner\OwnerController;
use App\Models\Booking;
use App\Models\CardEntry;
use App\Models\Product;
use App\Models\RatingChange;
use App\Models\SportRating;
use App\Models\Training;
use App\Models\TrainingVisit;
use App\Models\User;
use App\Models\VenueCard;
use App\Support\LocalTime;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Players of the place (owner → Players): their cards. The staff scan a player's QR (or find them by name / phone)
 * and on the card page top up, take payments, set the discount and the rating, mark trainings and sell from the shop.
 */
class CardController extends OwnerController
{
    public function index(Request $request)
    {
        $venue = $this->venue($request);
        $this->ensureAny($venue, ['wallet', 'rating']);
        $q = trim((string) $request->query('q', ''));
        $sport = $this->sport($venue);
        $cards = VenueCard::where('venue_id', $venue->id)->with('user')
            ->when($q !== '', fn ($w) => $w->whereHas('user', fn ($u) => $u->where('name', 'ilike', "%{$q}%")
                ->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $q).'%')))
            ->latest('updated_at')->limit(200)->get();
        $ratings = SportRating::whereIn('user_id', $cards->pluck('user_id'))->where('sport', $sport->value)->pluck('rating', 'user_id');

        return Inertia::render('owner/Players', [
            'q' => $q,
            'cards' => $cards->map(fn (VenueCard $c) => [
                'code' => $c->code, 'name' => $c->user?->name, 'phone' => $c->user?->phone ? Phone::format($c->user->phone) : null,
                'avatar' => $c->user?->avatarUrl(), 'userId' => $c->user_id, 'balance' => $c->balance, 'discount' => $c->discount_pct,
                'rating' => $ratings[$c->user_id] ?? null,
            ])->values(),
            'sport' => $sport->value,
        ]);
    }

    /** A card for a player found by phone (they don't have the QR on them). */
    public function store(Request $request, Cards $cards)
    {
        $venue = $this->venue($request);
        $data = $request->validate(['phone' => 'required|string|max:20']);
        $user = ($phone = Phone::normalize($data['phone'])) ? User::where('phone', $phone)->first() : null;
        if (! $user) {
            return back()->withErrors(['phone' => __('owner.club.no_player')]);
        }
        $card = $this->attempt(fn () => $cards->forPlayer($venue, $user));

        return redirect()->route('owner.cards.show', $card->code);
    }

    public function show(Request $request, string $code)
    {
        $venue = $this->venue($request);
        $card = $this->card($request, $code)->load('user');
        $user = $card->user;
        $sport = $this->sport($venue);
        $today = LocalTime::now()->startOfDay();
        $rating = SportRating::where('user_id', $user->id)->where('sport', $sport->value)->first();
        $bookings = Booking::where('venue_id', $venue->id)->where('user_id', $user->id)->whereIn('status', ['accepted', 'completed', 'pending'])
            ->where('starts_at', '>=', $today->subDays(1)->utc())->where('starts_at', '<', $today->addDays(2)->utc())->with('field')->orderBy('starts_at')->get();
        $paid = \App\Models\Payment::where('kind', 'booking')->whereIn('ref_id', $bookings->pluck('id'))->pluck('method', 'ref_id');
        $visited = TrainingVisit::where('user_id', $user->id)->whereDate('date', $today->toDateString())->pluck('training_id')->all();

        return Inertia::render('owner/Card', [
            'card' => ['code' => $card->code, 'balance' => $card->balance, 'discount' => $card->discount_pct, 'note' => $card->note],
            'player' => ['id' => $user->id, 'name' => $user->name, 'phone' => $user->phone ? Phone::format($user->phone) : null, 'tel' => $user->phone,
                'avatar' => $user->avatarUrl(), 'no_shows' => (int) $user->no_show_count],
            'sport' => $sport->value,
            'rating' => ['rating' => $rating?->rating, 'intl_id' => $rating?->intl_id,
                'history' => RatingChange::where('user_id', $user->id)->where('sport', $sport->value)->with('venue:id,name')->latest('id')->limit(10)->get()
                    ->map(fn (RatingChange $r) => ['old' => $r->old, 'new' => $r->new, 'note' => $r->note, 'where' => $r->venue?->name,
                        'at' => $r->created_at->setTimezone(LocalTime::tz())->format('d.m.Y')])->values()],
            'cashback' => (int) $venue->rule('cashback_pct'),
            'bookings' => $bookings->map(fn (Booking $b) => [...BookingPresenter::row($b), 'field' => BookingPresenter::fieldLabel($b->field), 'paid' => $paid[$b->id] ?? null])->values(),
            'trainings' => $venue->hasFeature('trainings') ? Training::where('venue_id', $venue->id)->where('active', true)->orderBy('starts_at')->get()
                ->map(fn (Training $t) => [...Trainings::present($t), 'today' => in_array($today->dayOfWeek, $t->weekdays, true), 'visited' => in_array($t->id, $visited, true)])->values() : [],
            'products' => $venue->hasFeature('shop') ? Product::where('venue_id', $venue->id)->where('active', true)->where('in_stock', true)->orderBy('sort')->orderBy('name')->get()
                ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->finalPrice(), 'photo' => $p->photoUrl()])->values() : [],
            'history' => CardEntry::where('card_id', $card->id)->with('author:id,name')->latest('id')->limit(40)->get()
                ->map(fn (CardEntry $e) => ['id' => $e->id, 'amount' => $e->amount, 'kind' => $e->kind, 'note' => $e->note, 'by' => $e->author?->name,
                    'at' => $e->created_at->setTimezone(LocalTime::tz())->format('d.m H:i')])->values(),
        ]);
    }

    public function topUp(Request $request, string $code, Cards $cards)
    {
        $data = $request->validate(['amount' => 'required|integer|min:1|max:10000000', 'note' => 'nullable|string|max:200']);
        $card = $this->card($request, $code);
        $this->attempt(fn () => $cards->topUp($card, (int) $data['amount'], $request->user(), $data['note'] ?? null));

        return back()->with('success', __('owner.club.topped_up'));
    }

    /** Take money: from the card or in cash, for "other" things (bookings, trainings and the shop have their own buttons). */
    public function charge(Request $request, string $code, Money $money)
    {
        $data = $request->validate(['amount' => 'required|integer|min:1|max:10000000', 'method' => 'required|in:card,cash', 'note' => 'nullable|string|max:200']);
        $card = $this->card($request, $code)->load('user');
        $this->attempt(fn () => $money->charge($this->venue($request), $request->user(), 'other', (int) $data['amount'], $data['method'], $card->user, null, $data['note'] ?? null));

        return back()->with('success', __('owner.club.paid'));
    }

    public function adjust(Request $request, string $code, Cards $cards)
    {
        $data = $request->validate(['amount' => 'required|integer|min:-10000000|max:10000000|not_in:0', 'note' => 'required|string|max:200']);
        $this->ensureOwner($request);
        $card = $this->card($request, $code);
        $this->attempt(fn () => $cards->adjust($card, (int) $data['amount'], $request->user(), $data['note']));

        return back()->with('success', __('owner.flash.saved'));
    }

    public function discount(Request $request, string $code, Cards $cards)
    {
        $data = $request->validate(['discount' => 'required|integer|min:0|max:100', 'note' => 'nullable|string|max:200']);
        $card = $this->card($request, $code);
        $this->attempt(fn () => $cards->setDiscount($card, (int) $data['discount'], $data['note'] ?? null));

        return back()->with('success', __('owner.flash.saved'));
    }

    public function rating(Request $request, string $code, Ratings $ratings)
    {
        $data = $request->validate(['rating' => 'nullable|integer|min:0|max:'.Ratings::MAX, 'intl_id' => 'nullable|string|max:40', 'note' => 'nullable|string|max:200']);
        $venue = $this->venue($request);
        $card = $this->card($request, $code)->load('user');
        $this->attempt(fn () => $ratings->set($card->user, $this->sport($venue), $data['rating'] ?? null, $data['intl_id'] ?? null, $venue, $request->user(), $data['note'] ?? null));

        return back()->with('success', __('owner.flash.saved'));
    }

    public function visit(Request $request, string $code, Training $training, Trainings $trainings)
    {
        abort_unless($training->venue_id === $this->venue($request)->id, 404);
        $data = $request->validate(['method' => ['nullable', Rule::in(['card', 'cash'])]]);
        $card = $this->card($request, $code)->load('user');
        $this->attempt(fn () => $trainings->visit($training, $card->user, $request->user(), $data['method'] ?? null));

        return back()->with('success', __('owner.club.visit_marked'));
    }

    public function sell(Request $request, string $code, Product $product, Shop $shop)
    {
        $venue = $this->venue($request);
        $data = $request->validate(['qty' => 'required|integer|min:1|max:100', 'method' => 'required|in:card,cash']);
        $card = $this->card($request, $code)->load('user');
        $this->attempt(fn () => $shop->sell($venue, $product, (int) $data['qty'], $data['method'], $request->user(), $card->user));

        return back()->with('success', __('owner.club.sold'));
    }

    /** "Paid" on a booking: by the player's card or cash (once). */
    public function payBooking(Request $request, Booking $booking, Money $money)
    {
        $this->ensureSameVenue($request, $booking);
        $data = $request->validate(['method' => 'required|in:card,cash']);
        $this->attempt(fn () => $money->payBooking($booking, $data['method'], $request->user()));

        return back()->with('success', __('owner.club.paid'));
    }

    private function card(Request $request, string $code): VenueCard
    {
        $venue = $this->venue($request);
        $this->ensureAny($venue, ['wallet', 'rating']);
        $card = app(Cards::class)->byCode($venue, $code);
        abort_unless($card, 404, __('owner.club.not_here'));

        return $card;
    }

    private function sport($venue): Sport
    {
        return $venue->sports()[0] ?? Sport::Football;
    }
}
