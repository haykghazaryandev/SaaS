<?php

namespace App\Domain\Club;

use App\Enums\Sport;
use App\Models\Booking;
use App\Models\SportRating;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueCard;
use Illuminate\Support\Collection;

/**
 * Rankings by the rating the clubs set (Owner → Our players → a player: rating), for sports that have one (ping-pong):
 * a club's ranking (the players who played there: bookings, cards, tournaments, or rated there), and the all-clubs one
 * (every rated player, with the club that set the rating). Equal ratings share a place. And a player's match history:
 * their tournament matches with the scores, wins / losses and titles.
 */
class Ranking
{
    /** The people who played at a place: bookings, a card there, its tournaments. */
    public static function playersOf(Venue $venue): array
    {
        return Booking::where('venue_id', $venue->id)->whereNotNull('user_id')->whereIn('status', ['accepted', 'completed', 'no_show'])->distinct()->pluck('user_id')
            ->merge(VenueCard::where('venue_id', $venue->id)->pluck('user_id'))
            ->merge(TournamentEntry::whereNotNull('user_id')->whereHas('tournament', fn ($t) => $t->where('venue_id', $venue->id))->pluck('user_id'))
            ->unique()->map(fn ($id) => (int) $id)->values()->all();
    }

    /** A club's ranking (its sport's rating): the players who played there or were rated there. */
    public function club(Venue $venue, ?int $limit = null): Collection
    {
        $sport = $this->ratedSport($venue);
        if (! $sport) {
            return collect();
        }
        $ratings = SportRating::where('sport', $sport->value)->whereNotNull('rating')
            ->where(fn ($q) => $q->whereIn('user_id', self::playersOf($venue))->orWhere('venue_id', $venue->id))
            ->with('user:id,name,username,avatar_path')->get();

        return $this->rows($ratings, $limit);
    }

    /** Every rated player of a sport, with the club that set the rating (the ping-pong page). */
    public function all(Sport $sport, ?int $limit = null): Collection
    {
        $ratings = SportRating::where('sport', $sport->value)->whereNotNull('rating')->with('user:id,name,username,avatar_path', 'venue:id,name,slug')->get();

        return $this->rows($ratings, $limit);
    }

    /** A player's place in a ranking (null: not in it). */
    public static function placeOf(Collection $rows, int $userId): ?array
    {
        return $rows->firstWhere('id', $userId);
    }

    private function rows(Collection $ratings, ?int $limit): Collection
    {
        $rows = $ratings->filter(fn (SportRating $r) => $r->user)->sortBy([['rating', 'desc'], [fn ($r) => mb_strtolower((string) $r->user->name), 'asc']])->values();
        $rank = 0;
        $prev = null;

        return $rows->map(function (SportRating $r, int $i) use (&$rank, &$prev) {
            // equal ratings share a place (1, 2, 2, 4)
            if ($r->rating !== $prev) {
                $rank = $i + 1;
                $prev = $r->rating;
            }

            return ['rank' => $rank, 'id' => $r->user->id, 'name' => $r->user->name, 'username' => $r->user->username, 'avatar' => $r->user->avatarUrl(),
                'rating' => (int) $r->rating, 'intl' => $r->intl_id, 'club' => $r->relationLoaded('venue') && $r->venue ? ['name' => $r->venue->name, 'slug' => $r->venue->slug, 'id' => $r->venue->id] : null];
        })->when($limit, fn ($c) => $c->take($limit))->values();
    }

    private function ratedSport(Venue $venue): ?Sport
    {
        return collect($venue->sports())->first(fn (Sport $s) => $s->rated());
    }

    /**
     * A player's tournament matches with a result, newest first: the opponent, the score from their side, won or lost,
     * the tournament (and division), the round; and the totals: matches, won, lost, titles (1st places).
     */
    public function history(User $user, int $limit = 30): array
    {
        $entries = TournamentEntry::where('user_id', $user->id)->get(['id', 'tournament_id', 'place']);
        $ids = $entries->pluck('id')->all();
        if (! $ids) {
            return ['played' => 0, 'won' => 0, 'lost' => 0, 'titles' => 0, 'matches' => []];
        }
        $matches = TournamentMatch::where(fn ($q) => $q->whereIn('a_id', $ids)->orWhereIn('b_id', $ids))
            ->whereNotNull('winner_id')->whereNotNull('a_id')->whereNotNull('b_id')
            ->with(['tournament:id,code,title,division,parent_id,starts_at', 'tournament.parent:id,title'])->latest('updated_at')->get();
        $rounds = TournamentMatch::whereIn('tournament_id', $matches->pluck('tournament_id')->unique())->groupBy('tournament_id')
            ->selectRaw('tournament_id, max(round) as n')->pluck('n', 'tournament_id');
        $others = TournamentEntry::whereIn('id', $matches->flatMap(fn ($m) => [$m->a_id, $m->b_id])->unique())->get(['id', 'first_name', 'last_name', 'user_id'])->keyBy('id');
        $rows = $matches->map(function (TournamentMatch $m) use ($ids, $others, $rounds) {
            $mineA = in_array($m->a_id, $ids, true);
            $me = $mineA ? $m->a_id : $m->b_id;
            $other = $others[$mineA ? $m->b_id : $m->a_id] ?? null;
            [$sa, $sb] = array_pad(explode(':', (string) $m->score), 2, null);
            $t = $m->tournament;

            return [
                'id' => $m->id, 'won' => $m->winner_id === $me,
                'opponent' => $other ? ['name' => Tournaments::name($other), 'id' => $other->user_id] : null,
                'score' => $m->score ? ($mineA ? "$sa:$sb" : "$sb:$sa") : null,
                'tournament' => ['code' => $t->code, 'title' => $t->parent?->title ?? $t->title, 'division' => $t->parent ? $t->division : null],
                // 1 = the final, 2 = the semi-final, 3 = the quarter-final, more: "round of N"
                'fromEnd' => (int) ($rounds[$m->tournament_id] ?? $m->round) - $m->round + 1,
                'date' => $t->starts_at?->toDateString(),
            ];
        });

        return [
            'played' => $rows->count(),
            'won' => $rows->where('won', true)->count(),
            'lost' => $rows->where('won', false)->count(),
            'titles' => $entries->where('place', 1)->count(),
            'matches' => $rows->take($limit)->values()->all(),
        ];
    }
}
