<?php

namespace App\Domain\Booking\AfterBooking;

use App\Domain\Booking\BookingException;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueReview;
use Illuminate\Support\Facades\DB;

/**
 * "How was the field?": the booker and the team rate a played game (never a no-show), within 7 days.
 * The place's rating_avg / rating_count follow every new or changed answer.
 */
class Reviews
{
    public const DAYS = 7;

    public function canRate(Booking $b, User $user): bool
    {
        return $b->status === BookingStatus::Completed && $b->ends_at->isPast() && $b->ends_at->greaterThan(now()->subDays(self::DAYS))
            && $b->attendees()->contains('id', $user->id);
    }

    public function rate(Booking $b, User $user, int $stars, ?string $comment = null): VenueReview
    {
        if ($stars < 1 || $stars > 5 || ! $this->canRate($b, $user)) {
            throw new BookingException('rate_closed');
        }

        return DB::transaction(function () use ($b, $user, $stars, $comment) {
            $venue = Venue::lockForUpdate()->find($b->venue_id);
            $review = VenueReview::where('booking_id', $b->id)->where('user_id', $user->id)->first();
            $count = (int) $venue->rating_count;
            $avg = (float) $venue->rating_avg;
            if ($review) {
                $avg = $count ? $avg + ($stars - $review->stars) / $count : $stars;
                $review->update(['stars' => $stars, 'comment' => $comment ?? $review->comment]);
            } else {
                $avg = ($avg * $count + $stars) / ($count + 1);
                $count++;
                $review = VenueReview::create(['venue_id' => $b->venue_id, 'booking_id' => $b->id, 'user_id' => $user->id, 'stars' => $stars, 'comment' => $comment]);
            }
            $venue->forceFill(['rating_avg' => round($avg, 2), 'rating_count' => $count])->save();

            return $review;
        });
    }
}
