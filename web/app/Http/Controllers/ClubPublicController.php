<?php

namespace App\Http\Controllers;

use App\Domain\Booking\BookingException;
use App\Domain\Chat\Chat;
use App\Domain\Club\Cards;
use App\Domain\Club\Tournaments;
use App\Http\Presenters\TournamentBracket;
use App\Models\Product;
use App\Models\Tournament;
use App\Models\VenueCard;
use App\Support\LocalTime;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Links that leave the app: a scanned card QR, a shop photo, a tournament registration link (Instagram bio). */
class ClubPublicController extends Controller
{
    /**
     * /c/{code}: what the card QR holds. Staff of the card's place get the card (in their owner panel, on that place);
     * the player gets their cards; anyone else just the club page. The code alone shows nothing about the player.
     */
    public function card(Request $request, string $code)
    {
        $card = VenueCard::where('code', $code)->with('venue')->first();
        abort_unless($card, 404);
        $user = $request->user();
        if ($user && $user->venues()->whereKey($card->venue_id)->exists()) {
            $request->session()->put('venue_id', $card->venue_id);
            // scanned at the desk: the staff may change this card's money for a while
            Cards::markScanned($card);

            return redirect()->route('owner.cards.show', $card->code);
        }
        if ($user && $user->id === $card->user_id) {
            return redirect()->route('me.cards', ['venue' => $card->venue->slug]);
        }

        return redirect()->route('places.show', $card->venue->slug);
    }

    public function photo(int $id, string $token)
    {
        $p = Product::where('id', $id)->where('photo_token', $token)->firstOrFail(['id', 'photo', 'photo_token']);

        return response(base64_decode((string) $p->photo), 200, ['Content-Type' => 'image/webp', 'Cache-Control' => 'public, max-age=31536000, immutable']);
    }

    public function tournament(Request $request, string $code)
    {
        $t = Tournament::where('code', $code)->with(['venue:id,slug,name', 'parent:id,code,title', 'divisions'])->firstOrFail();
        $user = $request->user();
        $at = $t->starts_at->setTimezone(LocalTime::tz());
        // divisions by rating: the tournament's page lists them (pick yours), each division is a tournament of its own
        $divisions = $t->divisions->isNotEmpty() ? $t->divisions : ($t->parent_id ? Tournament::where('parent_id', $t->parent_id)->orderByRaw('min_rating asc nulls first')->orderBy('id')->get() : collect());
        $myRating = $user ? Tournaments::ratingOf($t, $user->id, null) : null;
        $divisionList = $divisions->map(fn (Tournament $d) => ['code' => $d->code, 'division' => $d->division, 'min_rating' => $d->min_rating, 'max_rating' => $d->max_rating,
            'max_players' => $d->max_players, 'phase' => Tournaments::phase($d), 'current' => $d->id === $t->id,
            'count' => $d->entries()->where('status', '!=', 'declined')->count(),
            // my rating fits this division
            'fits' => $myRating !== null && ($d->min_rating === null || $myRating >= $d->min_rating) && ($d->max_rating === null || $myRating <= $d->max_rating),
            'mine' => $user && $d->entries()->where(fn ($q) => $q->where('user_id', $user->id)->when($user->phone, fn ($w) => $w->orWhere('phone', $user->phone)))->exists(),
        ])->values();
        // my registration: mine (my account, or my phone registered by the club)
        $mine = $user ? $t->entries()->where(fn ($q) => $q->where('user_id', $user->id)->when($user->phone, fn ($w) => $w->orWhere('phone', $user->phone)))->first() : null;
        $chat = $t->conversation()->first();
        $phase = Tournaments::phase($t);
        // a tournament with divisions: everybody registered in its divisions
        $count = $t->divisions->isNotEmpty() ? $divisionList->sum('count') : $t->entries()->where('status', '!=', 'declined')->count();

        return Inertia::render('player/Tournament', [
            'tournament' => ['code' => $t->code, 'title' => $t->title, 'sport' => $t->sport, 'date' => $at->toDateString(), 'time' => $at->format('H:i'),
                'description' => $t->description, 'options' => $t->options, 'phase' => $phase,
                // the form: only while registration is open and there is a place
                'open' => $phase === 'registration' && $t->divisions->isEmpty() && (! $t->max_players || $count < $t->max_players),
                'venue' => ['slug' => $t->venue->slug, 'name' => $t->venue->name], 'count' => $count,
                'accepted' => $t->entries()->where('status', 'accepted')->count(),
                // before the draw: who is in (avatars on the page)
                'players' => $phase === 'registration' || $phase === 'closed' ? $t->entries()->where('status', 'accepted')->orderBy('id')->limit(32)->get()
                    ->map(fn ($e) => ['id' => $e->id, 'first' => $e->first_name, 'last' => $e->last_name, 'me' => $e->id === $mine?->id])->values() : [],
                // the end: 1st, 2nd and the 3rd places
                'podium' => $phase === 'done' ? $t->entries()->whereNotNull('place')->where('place', '<=', 3)->orderBy('place')->get()
                    ->map(fn ($e) => ['place' => $e->place, 'name' => Tournaments::name($e)])->values() : [],
                'min_rating' => $t->min_rating, 'max_rating' => $t->max_rating, 'max_players' => $t->max_players, 'status' => $t->status,
                'division' => $t->division, 'parent' => $t->parent ? ['code' => $t->parent->code, 'title' => $t->parent->title] : null,
                'isParent' => $t->divisions->isNotEmpty(), 'divisions' => $divisionList, 'myRating' => $myRating],
            // who plays whom (after the draw), live: the public channel tournament.{code}
            'bracket' => TournamentBracket::of($t, $mine?->id),
            // my registration: its answer, and the chat once accepted
            'entry' => $mine ? ['status' => $mine->status, 'place' => $mine->place,
                // accepted with a fee not paid yet: "pay at the club, card or cash"
                'unpaid' => $mine->status === 'accepted' && ! $mine->payment_id ? Tournaments::fee($t, $mine) : 0] : null,
            'chat' => $chat && $user && app(Chat::class)->isMember($chat, $user) ? $chat->id : null,
            'me' => $user ? ['first_name' => explode(' ', (string) $user->name)[0] ?? '', 'last_name' => explode(' ', (string) $user->name, 2)[1] ?? '',
                'phone' => $user->phone ? Phone::format($user->phone) : null,
                'registered' => $mine !== null] : null,
        ]);
    }

    public function register(Request $request, string $code, Tournaments $tournaments)
    {
        $t = Tournament::where('code', $code)->firstOrFail();
        $user = $request->user();
        $data = $request->validate([
            'first_name' => 'required|string|min:2|max:60', 'last_name' => 'required|string|min:2|max:60',
            // the account's phone; asked only when the account has none
            'phone' => [$user->phone ? 'nullable' : 'required', 'string', 'max:20'],
            'level' => 'nullable|integer|min:0|max:4000', 'option' => 'required|integer|min:0|max:2',
        ]);
        try {
            $tournaments->register($t, ['phone' => $user->phone ?: ($data['phone'] ?? null)] + $data, $user);
        } catch (BookingException $e) {
            throw ValidationException::withMessages(['register' => $e->getMessage()]);
        }

        return back()->with('success', __('player.tournament.registered'));
    }
}
