<?php

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\Events\BookingChanged;
use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Shared helpers for booking actions: save with the overlap guard, write history, fire the event. */
trait BookingWriter
{
    /** Runs $fn in a transaction; the Postgres exclusion constraint (23P01) becomes BookingException('taken'). */
    protected function guarded(callable $fn): mixed
    {
        try {
            return DB::transaction($fn);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23P01') {
                throw new BookingException('taken');
            }
            throw $e;
        }
    }

    protected function log(Booking $booking, string $type, ?User $actor, array $changes = []): void
    {
        BookingEvent::create(['booking_id' => $booking->id, 'user_id' => $actor?->id, 'type' => $type, 'changes' => $changes ?: null]);
        DB::afterCommit(fn () => BookingChanged::dispatch($booking->fresh(['field', 'venue', 'user']), $type, $actor, $changes));
    }
}
