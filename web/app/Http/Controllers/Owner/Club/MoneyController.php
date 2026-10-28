<?php

namespace App\Http\Controllers\Owner\Club;

use App\Domain\Club\Commission;
use App\Domain\Club\Report;
use App\Http\Controllers\Owner\OwnerController;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Owner → Money: today / this week / this month / last month: sales by kind, cash in, paid from cards, top-ups, every
 * payment (places with the money feature), and for every place ZoneSpot's fee: its % of bookings, trainings and
 * tournament fees (not the shop, not top-ups), by day.
 */
class MoneyController extends OwnerController
{
    public function __invoke(Request $request, Report $report, Commission $commission)
    {
        $venue = $this->venue($request);
        $this->ensureOwner($request);
        $period = in_array($request->query('period'), ['today', 'week', 'month', 'prev_month'], true) ? $request->query('period') : ($venue->hasFeature('money') ? 'today' : 'month');
        [$from, $to] = Commission::period($period);

        return Inertia::render('owner/Money', [
            'period' => $period, 'from' => $from->toDateString(), 'to' => $to->subDay()->toDateString(),
            // the full report only where the admin switched the money feature on
            'report' => $venue->hasFeature('money') ? $report->between($venue, $from, $to) : null,
            'fee' => $commission->between($venue, $from, $to),
        ]);
    }
}
