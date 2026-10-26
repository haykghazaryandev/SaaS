<?php

namespace App\Filament\Resources\Sports\Pages;

use App\Filament\Resources\Sports\SportResource;
use Filament\Resources\Pages\ListRecords;

class ListSports extends ListRecords
{
    protected static string $resource = SportResource::class;
}
