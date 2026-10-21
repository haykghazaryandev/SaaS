<?php

namespace App\Filament\Resources\Venues\Pages;

use App\Filament\Resources\Venues\VenueResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVenue extends CreateRecord
{
    protected static string $resource = VenueResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return VenueResource::cleanData($data);
    }

    /** After creating the place, go to its page to add fields, prices, hours and the owner. */
    protected function getRedirectUrl(): string
    {
        return VenueResource::getUrl('edit', ['record' => $this->record]);
    }
}
