<?php

namespace App\Domain\Club;

use App\Enums\Sport;
use App\Models\Booking;
use App\Models\SportRating;
use App\Models\TournamentEntry;
use App\Models\Venue;
use App\Models\VenueCard;
use Illuminate\Support\Collection;

/**
 * A club's ranking by the rating it sets (Owner → Our players → a player: rating), for sports that have one
 * (ping-pong): the players who played there (bookings, cards, tournaments) or were rated there. Equal ratings share
 * a place. Shown on the place page (the top 5) and on its own page.
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
}
