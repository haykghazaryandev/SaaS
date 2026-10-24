<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('common.all')),
            'pending' => Tab::make(__('admin.dashboard.pending'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending'))
                ->badge(fn () => Booking::where('status', 'pending')->count() ?: null)->badgeColor('warning'),
            'upcoming' => Tab::make(__('admin.booking.upcoming'))->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['pending', 'accepted'])->where('starts_at', '>=', now())),
        ];
    }
}
