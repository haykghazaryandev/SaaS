<?php

namespace App\Http\Presenters;

use App\Domain\Club\Tournaments;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Models\TournamentMatch;

/** The bracket of a tournament for the owner page and the public page: rounds of matches, first round first. */
class TournamentBracket
{
    /** @return array{rounds: list<array{round: int, size: int, matches: list<array>}>, total: int}|null */
    public static function of(Tournament $t, ?int $myEntryId = null): ?array
    {
        if (! $t->drawn_at) {
            return null;
        }
        $matches = TournamentMatch::where('tournament_id', $t->id)->orderBy('round')->orderBy('slot')->get();
        if ($matches->isEmpty()) {
            return null;
        }
        $entries = TournamentEntry::whereIn('id', $matches->flatMap(fn ($m) => [$m->a_id, $m->b_id])->filter()->unique())->get()->keyBy('id');
        $total = (int) $matches->max('round');
        $side = fn (?int $id) => $id && isset($entries[$id]) ? [
            'id' => $id, 'name' => Tournaments::name($entries[$id]), 'first' => $entries[$id]->first_name, 'last' => $entries[$id]->last_name, 'rating' => Tournaments::ratingOf($t, $entries[$id]->user_id, $entries[$id]->level),
            'me' => $id === $myEntryId,
        ] : null;

        return [
            'total' => $total,
            'rounds' => $matches->groupBy('round')->map(fn ($ms, $r) => [
                'round' => (int) $r,
                // how many players are left in this round: 16 → "1/8", 4 → semi-final, 2 → final
                'size' => (2 ** ($total - (int) $r + 1)),
                'matches' => $ms->map(fn (TournamentMatch $m) => [
                    'id' => $m->id, 'slot' => $m->slot, 'a' => $side($m->a_id), 'b' => $side($m->b_id),
                    'winner' => $m->winner_id, 'score' => $m->score, 'live' => $m->live,
                    // a player went on without playing (fewer players than places)
                    'bye' => $m->round === 1 && (! $m->a_id || ! $m->b_id),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
