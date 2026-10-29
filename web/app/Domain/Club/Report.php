<?php

namespace App\Domain\Club;

use App\Models\Payment;
use App\Models\Venue;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * The place's money for a period: what it sold (bookings, trainings, shop, tournaments) and the cash that came in.
 * Paying from a card is a sale but not new cash (the cash came with the top-up), so the two are shown apart.
 * Only the place's own money: ZoneSpot's fee has its own page.
 */
class Report
{
    public function between(Venue $venue, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->query($venue, $from, $to)->get(['id', 'kind', 'method', 'amount', 'discount', 'created_at']);
        $sales = $rows->where('kind', '!=', 'topup');
        $byKind = [];
        foreach (['booking', 'training', 'shop', 'tournament'] as $k) {
            $byKind[$k] = (int) $sales->where('kind', $k)->sum('amount');
        }
        $days = [];
        foreach ($rows as $p) {
            $d = $p->created_at->setTimezone(LocalTime::tz())->toDateString();
            $days[$d] ??= ['date' => $d, 'sales' => 0, 'cash' => 0];
            if ($p->kind !== 'topup') {
                $days[$d]['sales'] += $p->amount;
            }
            if ($p->method === 'cash') {
                $days[$d]['cash'] += $p->amount;
            }
        }
        krsort($days);

        return [
            'sales' => (int) $sales->sum('amount'),
            'byKind' => $byKind,
            'cash' => (int) $rows->where('method', 'cash')->sum('amount'),
            'fromCards' => (int) $rows->where('method', 'card')->sum('amount'),
            'topups' => (int) $rows->where('kind', 'topup')->sum('amount'),
            'discounts' => (int) $rows->sum('discount'),
            'days' => array_values($days),
        ];
    }

    /** The payments of the period (newest first): the Money page's history, filtered and paged by the controller. */
    public function query(Venue $venue, CarbonImmutable $from, CarbonImmutable $to)
    {
        return Payment::where('venue_id', $venue->id)->where('created_at', '>=', $from->utc())->where('created_at', '<', $to->utc())->orderByDesc('id');
    }

    /** One payment of the history. */
    public static function row(Payment $p): array
    {
        return ['id' => $p->id, 'kind' => $p->kind, 'method' => $p->method, 'amount' => $p->amount, 'discount' => $p->discount,
            'who' => $p->user?->name, 'by' => $p->author?->name, 'note' => $p->note,
            'at' => $p->created_at->setTimezone(LocalTime::tz())->format('d.m H:i')];
    }
}
