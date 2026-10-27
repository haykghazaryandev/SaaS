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
        $t = Tournament::where('code', $code)->with('venue:id,slug,name')->firstOrFail();
        $user = $request->user();
        $at = $t->starts_at->setTimezone(LocalTime::tz());
        $mine = $user?->phone ? $t->entries()->where('phone', $user->phone)->first() : null;
        $chat = $t->conversation()->first();

        return Inertia::render('player/Tournament', [
            'tournament' => ['code' => $t->code, 'title' => $t->title, 'sport' => $t->sport, 'date' => $at->toDateString(), 'time' => $at->format('H:i'),
                'description' => $t->description, 'options' => $t->options, 'open' => $t->status === 'open' && $at->isFuture(),
                'venue' => ['slug' => $t->venue->slug, 'name' => $t->venue->name], 'count' => $t->entries()->where('status', '!=', 'declined')->count(),
                'min_rating' => $t->min_rating, 'max_rating' => $t->max_rating, 'max_players' => $t->max_players, 'status' => $t->status],
            // who plays whom (after the draw), live: the public channel tournament.{code}
            'bracket' => TournamentBracket::of($t, $mine?->id),
            // my registration: its answer, and the chat once accepted
            'entry' => $mine ? ['status' => $mine->status, 'place' => $mine->place,
                // accepted with a fee not paid yet: "pay at the club, card or cash"
                'unpaid' => $mine->status === 'accepted' && ! $mine->payment_id ? Tournaments::fee($t, $mine) : 0] : null,
            'chat' => $chat && $user && app(Chat::class)->isMember($chat, $user) ? $chat->id : null,
            'me' => $user ? ['first_name' => explode(' ', (string) $user->name)[0] ?? '', 'last_name' => explode(' ', (string) $user->name, 2)[1] ?? '',
                'phone' => $user->phone ? substr($user->phone, -8) : '',
                'registered' => $mine !== null] : null,
        ]);
    }

    public function register(Request $request, string $code, Tournaments $tournaments)
    {
        $t = Tournament::where('code', $code)->firstOrFail();
        $data = $request->validate([
            'first_name' => 'required|string|min:2|max:60', 'last_name' => 'required|string|min:2|max:60', 'phone' => 'required|string|max:20',
            'level' => 'nullable|integer|min:0|max:4000', 'option' => 'required|integer|min:0|max:2',
        ]);
        try {
            $tournaments->register($t, $data, $request->user());
        } catch (BookingException $e) {
            throw ValidationException::withMessages(['register' => $e->getMessage()]);
        }

        return back()->with('success', __('player.tournament.registered'));
    }
}
