<?php

namespace App\Http\Controllers\Owner\Club;

use App\Domain\Chat\Chat;
use App\Domain\Club\Cards;
use App\Domain\Club\Ratings;
use App\Domain\Social\SocialException;
use App\Enums\Sport;
use App\Http\Controllers\Owner\BookingPresenter;
use App\Http\Controllers\Owner\OwnerController;
use App\Models\Booking;
use App\Models\RatingChange;
use App\Models\SportRating;
use App\Models\TournamentEntry;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueCard;
use App\Support\LocalTime;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Owner → Players: everybody who played at this place (a booking, a card, a tournament), with their visits,
 * rating in the place's sport and card balance; filters by name / phone, rating and card. A player's page has
 * their history here and the rating (set without a scan: it is not money).
 */
class PlayerController extends OwnerController
{
    public function index(Request $request)
    {
        $venue = $this->venue($request);
        $this->ensureAny($venue, ['desk', 'wallet', 'rating']);
        $f = $request->validate([
            'q' => 'nullable|string|max:60', 'min' => 'nullable|integer|min:0', 'max' => 'nullable|integer|min:0',
            'card' => 'nullable|in:yes,no', 'sort' => 'nullable|in:last,visits,rating,name,noshows', 'noshow' => 'nullable|in:yes,no',
        ]);
        // how often each one did not come to THIS place (other places don't count here)
        $noShows = BookingPresenter::venueNoShows($venue->id);
        $sport = $this->sport($venue);
        $ids = self::playerIds($venue);
        $stats = Booking::where('venue_id', $venue->id)->whereIn('user_id', $ids)
            ->selectRaw("user_id, count(*) filter (where status in ('completed', 'accepted') and starts_at < now()) as played, max(starts_at) as last")
            ->groupBy('user_id')->get()->keyBy('user_id');
        $ratings = SportRating::whereIn('user_id', $ids)->where('sport', $sport->value)->pluck('rating', 'user_id');
        $cards = VenueCard::where('venue_id', $venue->id)->whereIn('user_id', $ids)->get(['user_id', 'balance', 'code'])->keyBy('user_id');
        $q = mb_strtolower(trim((string) ($f['q'] ?? '')));
        $digits = preg_replace('/\D/', '', $q);

        $rows = User::whereIn('id', $ids)->get(['id', 'name', 'phone', 'avatar_path'])->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'phone' => $u->phone ? Phone::format($u->phone) : null, 'tel' => $u->phone, 'avatar' => $u->avatarUrl(),
            'visits' => (int) ($stats[$u->id]->played ?? 0),
            'last' => isset($stats[$u->id]->last) ? CarbonImmutable::parse($stats[$u->id]->last)->setTimezone(LocalTime::tz())->format('d.m.Y') : null,
            'lastTs' => isset($stats[$u->id]->last) ? CarbonImmutable::parse($stats[$u->id]->last)->getTimestamp() : 0,
            'rating' => $ratings[$u->id] ?? null,
            'card' => isset($cards[$u->id]) ? ['balance' => $cards[$u->id]->balance, 'code' => $cards[$u->id]->code] : null,
            'noShows' => $noShows[$u->id] ?? 0,
        ])
            ->filter(fn ($r) => $q === '' || str_contains(mb_strtolower((string) $r['name']), $q) || ($digits !== '' && str_contains((string) $r['tel'], $digits)))
            ->filter(fn ($r) => ! isset($f['min']) || ($r['rating'] !== null && $r['rating'] >= $f['min']))
            ->filter(fn ($r) => ! isset($f['max']) || ($r['rating'] !== null && $r['rating'] <= $f['max']))
            ->filter(fn ($r) => ! isset($f['card']) || ($f['card'] === 'yes') === ($r['card'] !== null))
            ->filter(fn ($r) => ! isset($f['noshow']) || ($f['noshow'] === 'yes') === ($r['noShows'] > 0));
        $rows = match ($f['sort'] ?? 'last') {
            'visits' => $rows->sortByDesc('visits'),
            'rating' => $rows->sortByDesc(fn ($r) => $r['rating'] ?? -1),
            'name' => $rows->sortBy(fn ($r) => mb_strtolower((string) $r['name'])),
            'noshows' => $rows->sortByDesc('noShows'),
            default => $rows->sortByDesc('lastTs'),
        };

        return Inertia::render('owner/Players', [
            'filters' => ['q' => $f['q'] ?? '', 'min' => $f['min'] ?? null, 'max' => $f['max'] ?? null, 'card' => $f['card'] ?? null, 'noshow' => $f['noshow'] ?? null, 'sort' => $f['sort'] ?? 'last'],
            'players' => $rows->take(300)->values(),
            'total' => count($ids),
            'sport' => $sport->value,
        ]);
    }

    public function show(Request $request, User $user)
    {
        $venue = $this->venue($request);
        $this->ensureAny($venue, ['desk', 'wallet', 'rating']);
        abort_unless(in_array($user->id, self::playerIds($venue), true), 404);
        $sport = $this->sport($venue);
        $rating = SportRating::where('user_id', $user->id)->where('sport', $sport->value)->first();
        $card = VenueCard::where('venue_id', $venue->id)->where('user_id', $user->id)->first();
        $bookings = Booking::where('venue_id', $venue->id)->where('user_id', $user->id)->with('field')->latest('starts_at')->limit(40)->get();

        return Inertia::render('owner/Player', [
            'player' => ['id' => $user->id, 'name' => $user->name, 'phone' => $user->phone ? Phone::format($user->phone) : null, 'tel' => $user->phone,
                'avatar' => $user->avatarUrl(), 'no_shows' => (int) $user->no_show_count],
            'sport' => $sport->value,
            'rating' => ['rating' => $rating?->rating, 'intl_id' => $rating?->intl_id,
                'history' => RatingChange::where('user_id', $user->id)->where('sport', $sport->value)->with('venue:id,name')->latest('id')->limit(15)->get()
                    ->map(fn (RatingChange $r) => ['old' => $r->old, 'new' => $r->new, 'note' => $r->note, 'where' => $r->venue?->name,
                        'at' => $r->created_at->setTimezone(LocalTime::tz())->format('d.m.Y')])->values()],
            'card' => $card ? ['code' => $card->code, 'balance' => $card->balance, 'cashback' => Cards::levelOf($card)['pct']] : null,
            'bookings' => $bookings->map(fn (Booking $b) => [...BookingPresenter::row($b), 'field' => BookingPresenter::fieldLabel($b->field)])->values(),
        ]);
    }

    public function rating(Request $request, User $user, Ratings $ratings)
    {
        $venue = $this->venue($request);
        abort_unless($venue->hasFeature('rating') && in_array($user->id, self::playerIds($venue), true), 404);
        $data = $request->validate(['rating' => 'nullable|integer|min:0|max:'.Ratings::MAX, 'intl_id' => 'nullable|string|max:40', 'note' => 'nullable|string|max:200']);
        $this->attempt(fn () => $ratings->set($user, $this->sport($venue), $data['rating'] ?? null, $data['intl_id'] ?? null, $venue, $request->user(), $data['note'] ?? null));

        return back()->with('success', __('owner.flash.saved'));
    }

    /** Everybody who played at the place: bookings (not just requests), cards, tournament entries. */
    public static function playerIds(Venue $venue): array
    {
        return Booking::where('venue_id', $venue->id)->whereNotNull('user_id')->whereIn('status', ['accepted', 'completed', 'no_show'])->distinct()->pluck('user_id')
            ->merge(VenueCard::where('venue_id', $venue->id)->pluck('user_id'))
            ->merge(TournamentEntry::whereNotNull('user_id')->whereHas('tournament', fn ($t) => $t->where('venue_id', $venue->id))->pluck('user_id'))
            ->unique()->map(fn ($id) => (int) $id)->values()->all();
    }

    private function sport(Venue $venue): Sport
    {
        return $venue->sports()[0] ?? Sport::Football;
    }

    /** "Message": the place's chat with this player (the staff answer for the place). */
    public function chat(Request $request, User $user, Chat $chat)
    {
        $venue = $this->venue($request);
        try {
            $c = $chat->withVenue($user, $venue);
        } catch (SocialException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('owner.messages.show', $c->id);
    }
}
