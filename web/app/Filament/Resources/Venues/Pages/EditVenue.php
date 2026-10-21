<?php

namespace App\Filament\Resources\Venues\Pages;

use App\Filament\Resources\Venues\VenueResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditVenue extends EditRecord
{
    protected static string $resource = VenueResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return VenueResource::cleanData($data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')->label(__('admin.venue.open_site'))->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                ->url(fn () => url('/places/'.$this->record->slug), shouldOpenInNewTab: true),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
