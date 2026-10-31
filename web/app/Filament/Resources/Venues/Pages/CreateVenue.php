<?php

namespace App\Filament\Resources\Venues\Pages;

use App\Filament\Resources\Venues\VenueResource;
use App\Models\Venue;
use Filament\Resources\Pages\CreateRecord;

class CreateVenue extends CreateRecord
{
    protected static string $resource = VenueResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = VenueResource::cleanData($data);
        // features left as offered: none saved, so the place gets its sport's defaults once its courts are added
        // (a football place: no rating, cards or tournaments; ping-pong: everything)
        if (isset($data['features']) && $data['features'] === array_values(array_diff(Venue::FEATURES, ['wallet']))) {
            $data['features'] = null;
        }

        return $data;
    }

    /** After creating the place, go to its page to add fields, prices, hours and the owner. */
    protected function getRedirectUrl(): string
    {
        return VenueResource::getUrl('edit', ['record' => $this->record]);
    }
}
