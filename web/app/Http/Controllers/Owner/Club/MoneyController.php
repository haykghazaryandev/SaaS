<?php

namespace App\Http\Controllers\Owner\Club;

use App\Domain\Club\Commission;
use App\Domain\Club\FeePayments;
use App\Domain\Club\Report;
use App\Domain\Venues\Menus;
use App\Http\Controllers\Owner\OwnerController;
use App\Models\FeePayment;
use App\Support\LocalTime;
use App\Support\Paged;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Owner → Money (places with the money feature): today / this week / this month / last month: sales by kind, cash in,
 * paid from cards, top-ups, and the payments of the period, filtered (bookings, trainings, shop, tournaments, top-ups;
 * card / cash) a page at a time. Owner → ZoneSpot fee: only ZoneSpot's % for each kind (no history).
 */
class MoneyController extends OwnerController
{
    public const KINDS = ['booking', 'training', 'shop', 'tournament', 'topup'];

    public const FEE_PERIODS = ['today', 'week', 'prev_week', 'month', 'prev_month'];

    public function __invoke(Request $request, Report $report)
    {
        $venue = $this->venue($request);
        if (! $venue->hasFeature('money')) {
            return redirect()->route('owner.fee');
        }
        // staff given "Today" in Settings → Menus: only today's money
        $todayOnly = ! $this->isOwner($request) && ! Menus::staffMay($venue, 'money', 'all');
        $period = ! $todayOnly && in_array($request->query('period'), ['today', 'week', 'month', 'prev_month'], true) ? $request->query('period') : 'today';
        $kind = in_array($request->query('kind'), self::KINDS, true) ? $request->query('kind') : null;
        $method = in_array($request->query('method'), ['card', 'cash'], true) ? $request->query('method') : null;
        [$from, $to] = Commission::period($period);
        $rows = $report->query($venue, $from, $to)->with('user:id,name', 'author:id,name')
            ->when($kind, fn ($q) => $q->where('kind', $kind))->when($method, fn ($q) => $q->where('method', $method))
            ->paginate(20)->withQueryString();

        return Inertia::render('owner/Money', [
            'period' => $period, 'todayOnly' => $todayOnly, 'from' => $from->toDateString(), 'to' => $to->subDay()->toDateString(),
            'filters' => ['kind' => $kind, 'method' => $method],
            'report' => $report->between($venue, $from, $to),
            'payments' => Paged::of($rows, fn ($p) => Report::row($p)),
        ]);
    }

    /**
     * ZoneSpot's fee for a period (today / this week / last week / this month / last month): what the place took for
     * bookings, trainings and tournament fees, ZoneSpot's part of each, and the total to give (never the shop or top-ups).
     */
    public function fee(Request $request, Commission $commission)
    {
        $venue = $this->venue($request);
        $period = in_array($request->query('period'), self::FEE_PERIODS, true) ? $request->query('period') : 'month';
        [$from, $to] = Commission::period($period);
        $r = $commission->between($venue, $from, $to);
        $pct = Commission::pct($venue);
        // a place without the desk counts its played bookings ('unpaid'): they are bookings too
        $kinds = ['booking' => $r['byKind']['booking'] + ($r['byKind']['unpaid'] ?? 0), 'training' => $r['byKind']['training'], 'tournament' => $r['byKind']['tournament']];

        return Inertia::render('owner/Fee', [
            'pct' => $pct, 'period' => $period, 'from' => $from->toDateString(), 'to' => $to->subDay()->toDateString(),
            'base' => $r['base'], 'fee' => $r['fee'],
            'kinds' => collect($kinds)->map(fn ($base, $k) => ['kind' => $k, 'base' => $base, 'fee' => Commission::fee($base, $pct)])->values(),
            // a month the admin marked as paid to ZoneSpot
            'paidOn' => in_array($period, ['month', 'prev_month'], true)
                ? FeePayment::where('venue_id', $venue->id)->whereDate('month', FeePayments::monthOf($from))->first()?->paid_at?->setTimezone(LocalTime::tz())->format('d.m.Y')
                : null,
        ]);
    }
}
