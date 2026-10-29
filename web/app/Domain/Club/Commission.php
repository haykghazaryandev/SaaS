<?php

namespace App\Domain\Club;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Venue;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * ZoneSpot's fee: a % (venues.commission_pct, 5 by default, set per place by the admin) of what a place takes for
 * bookings, trainings and tournament fees — paid from the player's card or in cash, refunds taken off. Shop sales
 * and card top-ups are not counted (the top-up money counts when the card pays for a booking or a tournament).
 * Places that take the money at the desk (Now / the bill: wallet, shop or money switched on) count only the money taken.
 * Places without the desk (a football field paid in cash on the spot) record no payments: their played bookings count
 * with the booking price, on the day played ('unpaid'). A bill paid at the desk is never counted twice (paid_at).
 */
class Commission
{
    public const KINDS = ['booking', 'training', 'tournament'];

    public const DEFAULT_PCT = 5.0;

    /** The place's %: set by the admin, else the default. */
    public static function pct(Venue $venue): float
    {
        return (float) ($venue->commission_pct ?? self::DEFAULT_PCT);
    }

    /** One place for [$from, $to): the base by kind, the %, the fee, and the same per day. */
    public function between(Venue $venue, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->forVenues(collect([$venue]), $from, $to)['venues'][0];
    }

    /**
     * Many places for [$from, $to) (two grouped queries, whatever the number of places), with the totals.
     *
     * @param  Collection<int, Venue>  $venues
     */
    public function forVenues(Collection $venues, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = $venues->pluck('id')->all();
        $tz = LocalTime::tz();
        $paid = Payment::whereIn('venue_id', $ids)->whereIn('kind', self::KINDS)
            ->where('created_at', '>=', $from->utc())->where('created_at', '<', $to->utc())
            ->get(['venue_id', 'kind', 'amount', 'created_at']);
        // only places without the desk: their played bookings stand for the money
        $noDesk = $venues->reject(fn (Venue $v) => self::usesDesk($v))->pluck('id')->all();
        $unpaid = Booking::whereIn('venue_id', $noDesk)->where('status', BookingStatus::Completed->value)->whereNull('paid_at')
            ->where('starts_at', '>=', $from->utc())->where('starts_at', '<', $to->utc())
            ->get(['venue_id', 'price', 'starts_at']);

        $rows = $venues->map(function (Venue $v) use ($paid, $unpaid, $tz) {
            $pct = (float) ($v->commission_pct ?? self::DEFAULT_PCT);
            $by = ['booking' => 0, 'training' => 0, 'tournament' => 0, 'unpaid' => 0];
            $days = [];
            $add = function (string $kind, int $amount, $at) use (&$by, &$days, $tz) {
                $by[$kind] += $amount;
                $d = $at->setTimezone($tz)->toDateString();
                $days[$d] = ($days[$d] ?? 0) + $amount;
            };
            foreach ($paid->where('venue_id', $v->id) as $p) {
                $add($p->kind, (int) $p->amount, $p->created_at);
            }
            foreach ($unpaid->where('venue_id', $v->id) as $b) {
                $add('unpaid', (int) $b->price, $b->starts_at);
            }
            krsort($days);
            $base = array_sum($by);

            return ['id' => $v->id, 'name' => $v->name, 'slug' => $v->slug, 'pct' => $pct, 'byKind' => $by, 'base' => $base, 'desk' => self::usesDesk($v),
                'fee' => self::fee($base, $pct),
                'days' => collect($days)->map(fn ($sum, $d) => ['date' => $d, 'base' => $sum, 'fee' => self::fee($sum, $pct)])->values()->all()];
        })->values();

        return ['venues' => $rows->all(), 'base' => (int) $rows->sum('base'), 'fee' => (int) $rows->sum('fee')];
    }

    /** The place takes the money at the desk (the bill of a game, cards, the shop): its payments are the truth. */
    public static function usesDesk(Venue $v): bool
    {
        return $v->hasFeature('wallet') || $v->hasFeature('shop') || $v->hasFeature('money');
    }

    public static function fee(int $base, float $pct): int
    {
        return (int) round(max(0, $base) * $pct / 100);
    }

    /** today | week | month | prev_month (Yerevan time) → [from, to). */
    public static function period(string $period, ?CarbonImmutable $day = null): array
    {
        $today = ($day ?? LocalTime::now())->startOfDay();

        return match ($period) {
            'week' => [$today->startOfWeek(), $today->startOfWeek()->addWeek()],
            'month' => [$today->startOfMonth(), $today->startOfMonth()->addMonth()],
            'prev_month' => [$today->startOfMonth()->subMonth(), $today->startOfMonth()],
            default => [$today, $today->addDay()],
        };
    }
}
