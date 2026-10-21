<?php

namespace App\Filament\Resources\Venues\RelationManagers;

use App\Filament\Support\Options;
use App\Models\PriceRule;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Price per hour by field, weekdays and time band. The rule with the highest priority wins. */
class PriceRulesRelationManager extends RelationManager
{
    protected static string $relationship = 'priceRules';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.price.many');
    }

    public static function getModelLabel(): string
    {
        return __('admin.price.one');
    }

    private function fieldOptions(): array
    {
        return $this->getOwnerRecord()->fields()->get()->mapWithKeys(fn ($f) => [$f->id => __('telegram.field.'.$f->size->value)])->all();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('field_id')->label(__('admin.field.one'))->options(fn () => $this->fieldOptions())->required(),
            TextInput::make('price_per_hour')->label(__('admin.price.per_hour'))->integer()->minValue(0)->step(500)->suffix('֏')->required(),
            CheckboxList::make('weekdays')->label(__('admin.price.weekdays'))->options(Options::weekdays())->columns(7)->required()->columnSpanFull()
                ->bulkToggleable(),
            TextInput::make('from_time')->label(__('admin.price.from'))->placeholder('08:00')->regex(Options::TIME_REGEX)->required()
                ->formatStateUsing(fn ($state) => Options::hhmm($state)),
            TextInput::make('to_time')->label(__('admin.price.to'))->placeholder('24:00')->regex(Options::TIME_REGEX)->required()
                ->formatStateUsing(fn ($state) => Options::hhmm($state)),
            TextInput::make('priority')->label(__('admin.price.priority'))->integer()->default(1)->helperText(__('admin.price.priority_help')),
        ]);
    }

    public static function clean(array $data): array
    {
        $data['weekdays'] = collect($data['weekdays'] ?? [])->map(fn ($d) => (int) $d)->sort()->values()->all();
        $data['price_per_hour'] = (int) $data['price_per_hour'];
        $data['priority'] = (int) ($data['priority'] ?? 0);

        return $data;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('field'))
            ->defaultSort('field_id')
            ->columns([
                TextColumn::make('field.size')->label(__('admin.field.one'))->formatStateUsing(fn (PriceRule $record) => __('telegram.field.'.$record->field->size->value)),
                TextColumn::make('days')->label(__('admin.price.weekdays'))
                    ->getStateUsing(fn (PriceRule $record) => collect($record->weekdays)->map(fn ($d) => __('admin.weekday_short.'.$d))->implode(' ')),
                TextColumn::make('from_time')->label(__('admin.price.time'))->formatStateUsing(fn (PriceRule $record) => Options::hhmm($record->from_time).'–'.Options::hhmm($record->to_time)),
                TextColumn::make('price_per_hour')->label(__('admin.price.per_hour'))->formatStateUsing(fn ($state) => Options::amd($state))->weight('bold'),
                TextColumn::make('priority')->label(__('admin.price.priority')),
            ])
            ->filters([
                SelectFilter::make('field_id')->label(__('admin.field.one'))->options(fn () => $this->fieldOptions()),
            ])
            ->headerActions([
                // price_rules belong to fields, so create them directly (a has-many-through can't create)
                CreateAction::make()->using(fn (array $data) => PriceRule::create(self::clean($data))),
            ])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data) => self::clean($data)),
                DeleteAction::make(),
            ]);
    }
}
