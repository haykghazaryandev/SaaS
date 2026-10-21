<?php

namespace App\Filament\Resources\Venues\RelationManagers;

use App\Filament\Support\Options;
use App\Models\OpeningHour;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

class OpeningHoursRelationManager extends RelationManager
{
    protected static string $relationship = 'openingHours';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.hours.many');
    }

    public static function getModelLabel(): string
    {
        return __('admin.hours.one');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('weekday')->label(__('admin.hours.weekday'))->options(Options::weekdays())->required()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('venue_id', $this->getOwnerRecord()->id)),
            TextInput::make('opens_at')->label(__('admin.hours.opens'))->placeholder('08:00')->regex(Options::TIME_REGEX)->required()
                ->formatStateUsing(fn ($state) => Options::hhmm($state)),
            TextInput::make('closes_at')->label(__('admin.hours.closes'))->placeholder('24:00')->regex(Options::TIME_REGEX)->required()
                ->formatStateUsing(fn ($state) => Options::hhmm($state)),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (OpeningHour $h) => __('admin.weekday.'.$h->weekday))
            ->modifyQueryUsing(fn ($query) => $query->orderByRaw('(weekday + 6) % 7'))
            ->paginated(false)
            ->columns([
                TextColumn::make('weekday')->label(__('admin.hours.weekday'))->formatStateUsing(fn ($state) => __('admin.weekday.'.$state))->weight('bold'),
                TextColumn::make('opens_at')->label(__('admin.hours.opens'))->formatStateUsing(fn ($state) => Options::hhmm($state)),
                TextColumn::make('closes_at')->label(__('admin.hours.closes'))->formatStateUsing(fn ($state) => Options::hhmm($state)),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
