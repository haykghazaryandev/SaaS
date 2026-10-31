<?php

namespace App\Filament\Pages;

use App\Domain\Club\Commission;
use App\Domain\Club\FeePayments;
use App\Enums\Sport;
use App\Models\Venue;
use App\Support\LocalTime;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Admin → ZoneSpot fee: every place's fee for a day / week / month (any one: ‹ ›), by sport — its % (set on the place),
 * the base (bookings, trainings, tournament fees; not the shop, not top-ups) and what it owes, with the totals.
 */
class PlatformFee extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'fee';

    protected string $view = 'filament.pages.platform-fee';

    public string $period = 'month';

    public string $day = '';

    public string $sport = '';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.places');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.fee.title');
    }

    public function getTitle(): string
    {
        return __('admin.fee.title');
    }

    public function mount(): void
    {
        $this->day = LocalTime::now()->toDateString();
    }

    public function setPeriod(string $p): void
    {
        $this->period = in_array($p, ['day', 'week', 'month'], true) ? $p : 'month';
    }

    /** ‹ / ›: the previous / next day, week or month. */
    public function move(int $dir): void
    {
        $d = $this->anchor();
        $next = match ($this->period) {
            'day' => $d->addDays($dir),
            'week' => $d->addWeeks($dir),
            default => $d->startOfMonth()->addMonths($dir),
        };
        $this->day = $next->toDateString();
    }

    /** Month view: the place paid ZoneSpot's fee for this month (or the mark is taken back). */
    public function markPaid(int $venueId): void
    {
        $venue = Venue::findOrFail($venueId);
        app(FeePayments::class)->markPaid($venue, $this->anchor(), auth()->user());
        Notification::make()->title(__('admin.fee.marked_paid', ['name' => $venue->name]))->success()->send();
    }

    public function unmarkPaid(int $venueId): void
    {
        app(FeePayments::class)->unmark(Venue::findOrFail($venueId), $this->anchor());
    }

    private function anchor(): CarbonImmutable
    {
        return LocalTime::day($this->day ?: LocalTime::now()->toDateString());
    }

    protected function getViewData(): array
    {
        [$from, $to] = Commission::period(['day' => 'today', 'week' => 'week', 'month' => 'month'][$this->period], $this->anchor());
        $venues = Venue::orderBy('name')->get()
            ->filter(fn (Venue $v) => $this->sport === '' || collect($v->sports())->contains(fn ($s) => $s->value === $this->sport))->values();
        $data = app(Commission::class)->forVenues($venues, $from, $to);
        $sports = $venues->mapWithKeys(fn (Venue $v) => [$v->id => collect($v->sports())->map->icon()->implode(' ')]);
        // a month: who paid it already
        $paid = $this->period === 'month' ? app(FeePayments::class)->forMonth($venues->pluck('id')->all(), $this->anchor()) : collect();

        return [
            'rows' => collect($data['venues'])->sortByDesc('fee')->values()->all(),
            'base' => $data['base'], 'fee' => $data['fee'], 'sports' => $sports,
            'paid' => $paid->map(fn ($p) => ['at' => $p->paid_at->setTimezone(LocalTime::tz())->format('d.m.Y'), 'amount' => $p->amount])->all(),
            'paidTotal' => (int) $paid->sum('amount'),
            'label' => $this->period === 'day' ? $from->format('d.m.Y') : ($this->period === 'week' ? $from->format('d.m').' – '.$to->subDay()->format('d.m.Y') : $from->translatedFormat('F Y')),
            'sportOptions' => collect(Sport::cases())->mapWithKeys(fn ($s) => [$s->value => $s->icon().' '.$s->label()])->all(),
        ];
    }
}
