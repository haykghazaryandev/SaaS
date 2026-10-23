<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Booking;
use App\Models\User;
use App\Models\Venue;
use App\Support\LocalTime;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Dashboard numbers: bookings today (Yerevan), requests waiting for an answer, users. */
class BookballStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $day = LocalTime::now()->startOfDay();
        $today = Booking::whereIn('status', ['pending', 'accepted', 'completed', 'no_show'])
            ->where('starts_at', '>=', $day->utc())->where('starts_at', '<', $day->addDay()->utc());
        $todayCount = (clone $today)->count();
        $todayAmount = (clone $today)->whereIn('status', ['accepted', 'completed'])->sum('price');
        $pending = Booking::where('status', 'pending')->count();
        $oldestPending = Booking::where('status', 'pending')->min('created_at');
        $users = User::whereNotNull('registered_at')->count();
        $newUsers = User::whereNotNull('registered_at')->where('registered_at', '>=', now()->subDays(7))->count();
        $telegram = User::whereNotNull('telegram_chat_id')->count();

        return [
            Stat::make(__('admin.dashboard.today'), $todayCount)
                ->description(__('admin.dashboard.today_amount', ['amount' => number_format((int) $todayAmount, 0, '.', ' ')]))
                ->icon('heroicon-o-calendar-days')->color('primary')
                ->url(BookingResource::getUrl('index', ['filters' => ['day' => ['day' => $day->toDateString()]]])),
            Stat::make(__('admin.dashboard.pending'), $pending)
                ->description($oldestPending ? __('admin.dashboard.oldest', ['time' => Carbon::parse($oldestPending)->diffForHumans()]) : __('admin.dashboard.none_waiting'))
                ->icon('heroicon-o-clock')->color($pending ? 'warning' : 'success')
                ->url(BookingResource::getUrl('index', ['activeTab' => 'pending'])),
            Stat::make(__('admin.dashboard.users'), $users)
                ->description(__('admin.dashboard.users_more', ['new' => $newUsers, 'tg' => $telegram]))
                ->icon('heroicon-o-users')
                ->url(UserResource::getUrl('index')),
            Stat::make(__('admin.dashboard.places'), Venue::where('status', 'active')->count())
                ->description(__('admin.dashboard.places_more', ['all' => Venue::count()]))
                ->icon('heroicon-o-map-pin'),
        ];
    }
}
