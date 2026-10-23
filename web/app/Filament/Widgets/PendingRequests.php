<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Requests that no place has answered yet (oldest first). */
class PendingRequests extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.dashboard.pending'))
            ->query(Booking::query()->with('venue', 'field', 'user')->where('status', 'pending')->oldest('created_at'))
            ->columns(BookingResource::columns())
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading(__('admin.dashboard.none_waiting'))
            ->recordActions([
                ViewAction::make()->url(fn (Booking $record) => BookingResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
