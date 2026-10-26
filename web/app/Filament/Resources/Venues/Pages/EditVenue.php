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
        $data = VenueResource::cleanData($data);
        // rules the form doesn't show (the owner's cashback, training prices, late grace) stay as they are
        $data['rules'] = [...array_diff_key($this->record->rules ?? [], array_flip(VenueResource::FORM_RULES)), ...$data['rules']];

        return $data;
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
