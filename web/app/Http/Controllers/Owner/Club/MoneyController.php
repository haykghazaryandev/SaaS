<?php

namespace App\Http\Controllers\Owner\Club;

use App\Domain\Club\Report;
use App\Http\Controllers\Owner\OwnerController;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Owner → Money: today / this week / this month: sales by kind, cash in, paid from cards, top-ups, every payment. */
class MoneyController extends OwnerController
{
    public function __invoke(Request $request, Report $report)
    {
        $venue = $this->venue($request);
        $this->ensureAny($venue, ['money']);
        $this->ensureOwner($request);
        $period = in_array($request->query('period'), ['today', 'week', 'month'], true) ? $request->query('period') : 'today';
        $today = LocalTime::now()->startOfDay();
        [$from, $to] = match ($period) {
            'today' => [$today, $today->addDay()],
            'week' => [$today->startOfWeek(), $today->addDay()],
            'month' => [$today->startOfMonth(), $today->addDay()],
        };

        return Inertia::render('owner/Money', ['period' => $period, 'from' => $from->toDateString(), 'report' => $report->between($venue, $from, $to)]);
    }
}
