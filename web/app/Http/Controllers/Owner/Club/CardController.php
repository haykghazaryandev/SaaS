<?php

namespace App\Http\Controllers\Owner\Club;

use App\Domain\Booking\BookingException;
use App\Domain\Club\Cards;
use App\Http\Controllers\Owner\OwnerController;
use App\Models\Booking;
use App\Models\CardEntry;
use App\Models\Payment;
use App\Models\User;
use App\Models\VenueCard;
use App\Support\LocalTime;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Owner → Cards: the players' cards at this place. Anyone of the staff can open a card (the scan is a quick way
 * to open it). Money goes ONTO a card only right after scanning its QR (the player at the desk with their phone);
 * money comes OFF a card only on a booking's check (time + items), never from here.
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
        // what each card has paid here: its cashback level
        $spent = Payment::whereIn('card_id', $cards->pluck('id'))->where('kind', '!=', 'topup')->groupBy('card_id')
            ->selectRaw('card_id, sum(amount) as paid')->pluck('paid', 'card_id');

        return Inertia::render('owner/Cards', [
            'q' => $q,
            'cards' => $cards->map(fn (VenueCard $c) => [
                'code' => $c->code, 'name' => $c->user?->name, 'phone' => $c->user?->phone ? Phone::format($c->user->phone) : null,
                'avatar' => $c->user?->avatarUrl(), 'userId' => $c->user_id, 'balance' => $c->balance, 'cashback' => Cards::level($venue, (int) ($spent[$c->id] ?? 0))['pct'],
            ])->values(),
            'totals' => ['cards' => VenueCard::where('venue_id', $venue->id)->count(), 'balance' => (int) VenueCard::where('venue_id', $venue->id)->sum('balance')],
        ]);
    }

    /** A card for a player (from their page, or by phone): made empty; money goes on after a scan. */
    public function store(Request $request, Cards $cards)
    {
        $venue = $this->venue($request);
        $data = $request->validate(['phone' => 'nullable|string|max:20', 'user_id' => 'nullable|integer', 'back' => 'nullable|string|max:200']);
        $user = ! empty($data['user_id']) ? User::find($data['user_id'])
            : (($phone = Phone::normalize($data['phone'] ?? null)) ? User::where('phone', $phone)->first() : null);
        if (! $user) {
            throw ValidationException::withMessages(['phone' => __('owner.club.no_player')]);
        }
        $card = $this->attempt(fn () => $cards->forPlayer($venue, $user));
        // made right now at the desk with the player: money can go on at once (like after a scan)
        if ($card->wasRecentlyCreated) {
            Cards::markScanned($card);
        }

        return redirect()->route('owner.cards.show', array_filter(['code' => $card->code, 'back' => self::back($data['back'] ?? null)]));
    }

    public function show(Request $request, string $code)
    {
        $venue = $this->venue($request);
        $card = $this->card($request, $code)->load('user');
        $user = $card->user;

        return Inertia::render('owner/Card', [
            // opened from a page (a tournament's players): a way back there
            'back' => self::back($request->query('back')),
            'card' => ['code' => $card->code, 'balance' => $card->balance, 'note' => $card->note,
                // a top-up only right after a scan (Cards::SCAN_MINUTES)
                'scanned' => Cards::scanned($card)],
            'player' => ['id' => $user->id, 'name' => $user->name, 'phone' => $user->phone ? Phone::format($user->phone) : null, 'tel' => $user->phone,
                'avatar' => $user->avatarUrl(), 'no_shows' => (int) $user->no_show_count],
            // the cashback level: % now, paid so far, the next level (by itself, from the place's levels)
            'level' => Cards::level($venue, Cards::spent($card)),
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

    /** A way back inside the owner web only (/owner/…). */
    private static function back(?string $path): ?string
    {
        return $path && preg_match('#^/owner/[A-Za-z0-9/_?=&.-]*$#', $path) ? $path : null;
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
