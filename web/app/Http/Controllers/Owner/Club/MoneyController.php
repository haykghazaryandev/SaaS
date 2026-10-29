<?php

namespace App\Http\Controllers\Owner\Club;

use App\Domain\Club\Commission;
use App\Domain\Club\Report;
use App\Http\Controllers\Owner\OwnerController;
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

    public function __invoke(Request $request, Report $report)
    {
        $venue = $this->venue($request);
        if (! $venue->hasFeature('money')) {
            return redirect()->route('owner.fee');
        }
        $period = in_array($request->query('period'), ['today', 'week', 'month', 'prev_month'], true) ? $request->query('period') : 'today';
        $kind = in_array($request->query('kind'), self::KINDS, true) ? $request->query('kind') : null;
        $method = in_array($request->query('method'), ['card', 'cash'], true) ? $request->query('method') : null;
        [$from, $to] = Commission::period($period);
        $rows = $report->query($venue, $from, $to)->with('user:id,name', 'author:id,name')
            ->when($kind, fn ($q) => $q->where('kind', $kind))->when($method, fn ($q) => $q->where('method', $method))
            ->paginate(20)->withQueryString();

        return Inertia::render('owner/Money', [
            'period' => $period, 'from' => $from->toDateString(), 'to' => $to->subDay()->toDateString(),
            'filters' => ['kind' => $kind, 'method' => $method],
            'report' => $report->between($venue, $from, $to),
            'payments' => Paged::of($rows, fn ($p) => Report::row($p)),
        ]);
    }

    /** ZoneSpot's fee: the % of each kind it is taken from (bookings, trainings, tournament fees; never the shop). */
    public function fee(Request $request)
    {
        $venue = $this->venue($request);

        return Inertia::render('owner/Fee', ['pct' => Commission::pct($venue)]);
    }
}
