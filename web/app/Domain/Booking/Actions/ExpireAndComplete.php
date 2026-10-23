<?php

namespace App\Domain\Booking\Actions;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Venue;

/**
 * Scheduled every minute (routes/console.php):
 * - a pending request expires when the place did not answer within its request_expire_min,
 *   or 30 minutes before the start, whichever comes first (the player gets a message);
 * - an accepted booking becomes "completed" once it has ended.
 */
class ExpireAndComplete
{
    use BookingWriter;

    public function expirePending(): int
    {
        $n = 0;
        $pending = Booking::with('venue')->where('status', BookingStatus::Pending)->where('created_at', '<', now()->subMinutes(5))->get();
        foreach ($pending as $b) {
            $limit = min($b->created_at->copy()->addMinutes($b->venue->rule('request_expire_min'))->getTimestamp(), $b->starts_at->copy()->subMinutes(30)->getTimestamp());
            if (now()->getTimestamp() < $limit) {
                continue;
            }
            $this->guarded(function () use ($b) {
                $fresh = Booking::lockForUpdate()->find($b->id);
                if ($fresh?->status === BookingStatus::Pending) {
                    $fresh->update(['status' => BookingStatus::Expired, 'cancelled_by' => 'system', 'decided_at' => now()]);
                    $this->log($fresh, 'expired', null);
                }
            });
            $n++;
        }

        return $n;
    }

    public function completeEnded(): int
    {
        return Booking::where('status', BookingStatus::Accepted)->where('ends_at', '<', now())->update(['status' => BookingStatus::Completed]);
    }
}
