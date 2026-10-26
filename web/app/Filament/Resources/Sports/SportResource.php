<?php

namespace App\Filament\Resources\Sports;

use App\Enums\Sport;
use App\Filament\Resources\Sports\Pages\ListSports;
use App\Models\SportSetting;
use App\Models\Venue;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/** Admin → Sports: which sports players see on the home page, in which order. New sports are added in code (App\Enums\Sport). */
class SportResource extends Resource
{
    protected static ?string $model = SportSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?int $navigationSort = 0;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.places');
    }

    public static function getModelLabel(): string
    {
        return __('admin.sport.one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.sport.many');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $places = fn (SportSetting $s) => Venue::active()->with('fields')->get()->filter(fn (Venue $v) => in_array($s->sport(), $v->sports(), true))->count();

        return $table
            ->defaultSort('sort')
            ->paginated(false)
            ->description(__('admin.sport.help'))
            ->columns([
                TextColumn::make('key')->label(__('admin.sport.one'))->weight('bold')
                    ->formatStateUsing(fn (SportSetting $record) => ($record->sport()?->icon() ?? '').' '.($record->sport()?->label() ?? $record->key)),
                ToggleColumn::make('visible')->label(__('admin.sport.visible')),
                TextColumn::make('places')->label(__('common.places'))->getStateUsing($places)->alignCenter(),
                TextInputColumn::make('sort')->label(__('admin.field.sort'))->type('number')->rules(['integer', 'min:0', 'max:99'])->width('110px'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSports::route('/')];
    }
}
