<?php

namespace App\Domain\Club;

use App\Domain\Booking\BookingException;
use App\Enums\Sport;
use App\Models\RatingChange;
use App\Models\SportRating;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;

/**
 * Ratings per sport: a player has a ping-pong rating and, apart from it, a football one.
 * The staff of a place with the rating feature set it after watching games; every change is kept with a note.
 */
class Ratings
{
    public const MAX = 4000;

    public function set(User $player, Sport $sport, ?int $rating, ?string $intlId, Venue $venue, User $by, ?string $note = null): SportRating
    {
        if (! $venue->hasFeature('rating')) {
            throw new BookingException('feature_off');
        }
        if ($rating !== null && ($rating < 0 || $rating > self::MAX)) {
            throw new BookingException('bad_rating');
        }

        return DB::transaction(function () use ($player, $sport, $rating, $intlId, $venue, $by, $note) {
            $row = SportRating::firstOrNew(['user_id' => $player->id, 'sport' => $sport->value]);
            $old = $row->exists ? $row->rating : null;
            $row->fill(['rating' => $rating, 'intl_id' => $intlId ?: null, 'venue_id' => $venue->id])->save();
            if ($old !== $rating || $note) {
                RatingChange::create(['user_id' => $player->id, 'sport' => $sport->value, 'old' => $old, 'new' => $rating,
                    'note' => $note, 'venue_id' => $venue->id, 'created_by' => $by->id]);
            }

            return $row;
        });
    }

    /** [sport => ['rating' => 1450, 'intl_id' => …]] for a profile. */
    public static function of(User $player): array
    {
        return SportRating::where('user_id', $player->id)->whereNotNull('rating')->get()
            ->mapWithKeys(fn (SportRating $r) => [$r->sport => ['rating' => $r->rating, 'intl_id' => $r->intl_id]])->all();
    }
}
