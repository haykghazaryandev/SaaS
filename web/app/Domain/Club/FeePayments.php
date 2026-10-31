<?php

namespace App\Domain\Club;

use App\Models\FeePayment;
use App\Models\User;
use App\Models\Venue;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Who paid ZoneSpot's monthly fee: the admin marks a place's month as paid (the fee owed then is kept, so a later
 * change of the numbers doesn't change what was paid) or takes the mark back.
 */
class FeePayments
{
    public function __construct(private Commission $commission) {}

    public static function monthOf(CarbonImmutable $day): string
    {
        return $day->setTimezone(LocalTime::tz())->startOfMonth()->toDateString();
    }

    public function markPaid(Venue $venue, CarbonImmutable $month, User $by, ?string $note = null): FeePayment
    {
        $from = LocalTime::day(self::monthOf($month));
        $owed = $this->commission->between($venue, $from, $from->addMonth())['fee'];

        return FeePayment::updateOrCreate(
            ['venue_id' => $venue->id, 'month' => self::monthOf($month)],
            ['amount' => $owed, 'paid_at' => now(), 'note' => $note ? mb_substr(trim($note), 0, 200) : null, 'marked_by' => $by->id],
        );
    }

    public function unmark(Venue $venue, CarbonImmutable $month): void
    {
        FeePayment::where('venue_id', $venue->id)->whereDate('month', self::monthOf($month))->delete();
    }

    /** @return Collection<int, FeePayment> by venue id */
    public function forMonth(array $venueIds, CarbonImmutable $month): Collection
    {
        return FeePayment::whereIn('venue_id', $venueIds)->whereDate('month', self::monthOf($month))->get()->keyBy('venue_id');
    }
}
