<?php

namespace App\Http\Controllers\Owner\Club;

use App\Domain\Booking\BookingException;
use App\Domain\Club\Cards;
use App\Domain\Club\Money;
use App\Domain\Club\Shop;
use App\Http\Controllers\Owner\OwnerController;
use App\Models\CardEntry;
use App\Models\Product;
use App\Models\User;
use App\Models\VenueCard;
use App\Support\LocalTime;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Owner → Cards: the players' cards at this place. Anyone of the staff can look at a card; money on it (top up,
 * take, discount, a sale) changes only for a while after scanning the player's QR (or typing the code from their
 * screen): the player stands at the desk with their phone, nobody changes a balance from a list.
 */
class CardController extends OwnerController
{
    public function index(Request $request)
    {
        $venue = $this->venue($request);
        $this->ensureAny($venue, ['wallet']);
        $q = trim((string) $request->query('q', ''));
        $digits = preg_replace('/\D/', '', $q);
        $cards = VenueCard::where('venue_id', $venue->id)->with('user')
            ->when($q !== '', fn ($w) => $w->whereHas('user', fn ($u) => $u->where('name', 'ilike', "%{$q}%")
                ->when($digits !== '', fn ($x) => $x->orWhere('phone', 'like', "%{$digits}%"))))
            ->latest('updated_at')->limit(300)->get();

        return Inertia::render('owner/Cards', [
            'q' => $q,
            'cards' => $cards->map(fn (VenueCard $c) => [
                'code' => $c->code, 'name' => $c->user?->name, 'phone' => $c->user?->phone ? Phone::format($c->user->phone) : null,
                'avatar' => $c->user?->avatarUrl(), 'userId' => $c->user_id, 'balance' => $c->balance, 'discount' => $c->discount_pct,
            ])->values(),
            'totals' => ['cards' => VenueCard::where('venue_id', $venue->id)->count(), 'balance' => (int) VenueCard::where('venue_id', $venue->id)->sum('balance')],
        ]);
    }

    /** A card for a player (from their page, or by phone): made empty; money goes on after a scan. */
    public function store(Request $request, Cards $cards)
    {
        $venue = $this->venue($request);
        $data = $request->validate(['phone' => 'nullable|string|max:20', 'user_id' => 'nullable|integer']);
        $user = ! empty($data['user_id']) ? User::find($data['user_id'])
            : (($phone = Phone::normalize($data['phone'] ?? null)) ? User::where('phone', $phone)->first() : null);
        if (! $user) {
            throw ValidationException::withMessages(['phone' => __('owner.club.no_player')]);
        }
        $card = $this->attempt(fn () => $cards->forPlayer($venue, $user));

        return redirect()->route('owner.cards.show', $card->code);
    }

    public function show(Request $request, string $code)
    {
        $venue = $this->venue($request);
        $card = $this->card($request, $code)->load('user');
        $user = $card->user;

        return Inertia::render('owner/Card', [
            'card' => ['code' => $card->code, 'balance' => $card->balance, 'discount' => $card->discount_pct, 'note' => $card->note,
                // money changes only right after a scan (Cards::SCAN_MINUTES)
                'scanned' => Cards::scanned($card)],
            'player' => ['id' => $user->id, 'name' => $user->name, 'phone' => $user->phone ? Phone::format($user->phone) : null, 'tel' => $user->phone,
                'avatar' => $user->avatarUrl(), 'no_shows' => (int) $user->no_show_count],
            'cashback' => (int) $venue->rule('cashback_pct'),
            'products' => $venue->hasFeature('shop') ? Product::where('venue_id', $venue->id)->where('active', true)->where('in_stock', true)->orderBy('sort')->orderBy('name')->get()
                ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->finalPrice(), 'photo' => $p->photoUrl()])->values() : [],
            'history' => CardEntry::where('card_id', $card->id)->with('author:id,name')->latest('id')->limit(60)->get()
                ->map(fn (CardEntry $e) => ['id' => $e->id, 'amount' => $e->amount, 'kind' => $e->kind, 'note' => $e->note, 'by' => $e->author?->name,
                    'at' => $e->created_at->setTimezone(LocalTime::tz())->format('d.m H:i')])->values(),
        ]);
    }

    public function topUp(Request $request, string $code, Cards $cards)
    {
        $data = $request->validate(['amount' => 'required|integer|min:1|max:10000000', 'note' => 'nullable|string|max:200']);
        $card = $this->scannedCard($request, $code);
        $this->attempt(fn () => $cards->topUp($card, (int) $data['amount'], $request->user(), $data['note'] ?? null));

        return back()->with('success', __('owner.club.topped_up'));
    }

    /** Take money from the card for something else (not a booking: that is paid on its bill). */
    public function charge(Request $request, string $code, Money $money)
    {
        $data = $request->validate(['amount' => 'required|integer|min:1|max:10000000', 'note' => 'nullable|string|max:200']);
        $card = $this->scannedCard($request, $code)->load('user');
        $this->attempt(fn () => $money->charge($this->venue($request), $request->user(), 'other', (int) $data['amount'], 'card', $card->user, null, $data['note'] ?? null, discount: false));

        return back()->with('success', __('owner.club.paid'));
    }

    public function discount(Request $request, string $code, Cards $cards)
    {
        $data = $request->validate(['discount' => 'required|integer|min:0|max:100', 'note' => 'nullable|string|max:200']);
        $card = $this->scannedCard($request, $code);
        $this->attempt(fn () => $cards->setDiscount($card, (int) $data['discount'], $data['note'] ?? null));

        return back()->with('success', __('owner.flash.saved'));
    }

    public function sell(Request $request, string $code, Product $product, Shop $shop)
    {
        $venue = $this->venue($request);
        $data = $request->validate(['qty' => 'required|integer|min:1|max:100']);
        $card = $this->scannedCard($request, $code)->load('user');
        $this->attempt(fn () => $shop->sell($venue, $product, (int) $data['qty'], 'card', $request->user(), $card->user));

        return back()->with('success', __('owner.club.sold'));
    }

    private function card(Request $request, string $code): VenueCard
    {
        $venue = $this->venue($request);
        $this->ensureAny($venue, ['wallet']);
        $card = app(Cards::class)->byCode($venue, $code);
        abort_unless($card, 404, __('owner.club.not_here'));

        return $card;
    }

    private function scannedCard(Request $request, string $code): VenueCard
    {
        $card = $this->card($request, $code);
        if (! Cards::scanned($card)) {
            throw ValidationException::withMessages(['booking' => (new BookingException('scan_first'))->getMessage()]);
        }

        return $card;
    }
}
