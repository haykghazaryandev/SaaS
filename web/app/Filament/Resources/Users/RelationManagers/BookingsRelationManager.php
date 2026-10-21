<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.booking.many');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('venue', 'field'))
            ->defaultSort('starts_at', 'desc')
            ->columns(BookingResource::columns())
            ->recordActions([
                Action::make('open')->label(__('admin.booking.open'))->icon('heroicon-o-eye')->color('gray')
                    ->url(fn (Booking $record) => BookingResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
