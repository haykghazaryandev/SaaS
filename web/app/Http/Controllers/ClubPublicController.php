<?php

namespace App\Http\Controllers;

use App\Domain\Booking\BookingException;
use App\Domain\Chat\Chat;
use App\Domain\Club\Cards;
use App\Domain\Club\Tournaments;
use App\Http\Presenters\TournamentBracket;
use App\Models\Product;
use App\Models\Tournament;
use App\Models\TournamentMatch;
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
        $divisionList = $divisions->map(fn (Tournament $d) => $this->division($d, $t, $user, $myRating))->values();
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

    /**
     * A division's card on the tournament page: where it is and what matters there — registration (places, my rating
     * fits, my answer), the draw made (my first opponent), live (matches on now, the stage, one live score, how far),
     * over (the champion and the runner-up, my place).
     */
    private function division(Tournament $d, Tournament $current, $user, ?int $myRating): array
    {
        $phase = Tournaments::phase($d);
        $mine = $user ? $d->entries()->where(fn ($q) => $q->where('user_id', $user->id)->when($user->phone, fn ($w) => $w->orWhere('phone', $user->phone)))->first() : null;
        $out = ['code' => $d->code, 'division' => $d->division, 'min_rating' => $d->min_rating, 'max_rating' => $d->max_rating,
            'max_players' => $d->max_players, 'phase' => $phase, 'current' => $d->id === $current->id,
            'count' => $d->entries()->where('status', '!=', 'declined')->count(),
            'accepted' => $d->entries()->where('status', 'accepted')->count(),
            // my rating fits this division
            'fits' => $myRating !== null && ($d->min_rating === null || $myRating >= $d->min_rating) && ($d->max_rating === null || $myRating <= $d->max_rating),
            'mine' => $mine !== null,
            'my' => $mine ? ['status' => $mine->status, 'place' => $mine->place] : null,
            'live' => 0, 'stage' => null, 'liveMatch' => null, 'played' => 0, 'total' => 0, 'opponent' => null, 'podium' => []];
        if (! $d->drawn_at) {
            return $out;
        }
        $matches = TournamentMatch::where('tournament_id', $d->id)->with(['a:id,first_name,last_name', 'b:id,first_name,last_name'])->orderBy('round')->orderBy('slot')->get();
        $rounds = (int) $matches->max('round');
        $real = $matches->filter(fn ($m) => $m->round > 1 || ($m->a_id && $m->b_id));     // byes are not matches
        $out['total'] = $real->count();
        $out['played'] = $real->filter(fn ($m) => $m->winner_id && $m->a_id && $m->b_id)->count();
        $live = $matches->where('live', true);
        $out['live'] = $live->count();
        if ($m = $live->first()) {
            $out['liveMatch'] = ['a' => Tournaments::name($m->a), 'b' => Tournaments::name($m->b), 'score' => $m->score];
        }
        // the stage: the first round with a match still to play
        $r = $real->first(fn ($m) => ! $m->winner_id)?->round;
        if ($r) {
            $left = $rounds - $r;
            $out['stage'] = $left === 0 ? ['key' => 'final'] : ($left === 1 ? ['key' => 'semi'] : ($left === 2 ? ['key' => 'quarter'] : ['key' => 'round_of', 'n' => 2 ** ($left + 1)]));
        }
        // after the draw, before my match: who I play
        if ($mine && $mine->status === 'accepted' && $phase !== 'done') {
            $my = $matches->first(fn ($m) => ! $m->winner_id && ($m->a_id === $mine->id || $m->b_id === $mine->id));
            $vs = $my ? ($my->a_id === $mine->id ? $my->b : $my->a) : null;
            $out['opponent'] = $vs ? Tournaments::name($vs) : null;
        }
        if ($phase === 'done') {
            $out['podium'] = $d->entries()->whereNotNull('place')->where('place', '<=', 2)->orderBy('place')->get()
                ->map(fn ($e) => ['place' => $e->place, 'name' => Tournaments::name($e)])->values()->all();
        }

        return $out;
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
