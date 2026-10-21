<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Actions\CreateOwnerAccountAction;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateOwnerAccountAction::make(),
        ];
    }
}
