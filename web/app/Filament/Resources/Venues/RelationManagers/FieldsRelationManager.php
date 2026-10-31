<?php

namespace App\Filament\Resources\Venues\RelationManagers;

use App\Domain\Venues\Courts;
use App\Enums\FieldSize;
use App\Filament\Support\Options;
use App\Models\Field;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class FieldsRelationManager extends RelationManager
{
    protected static string $relationship = 'fields';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.field.many');
    }

    public static function getModelLabel(): string
    {
        return __('admin.field.one');
    }

    public function form(Schema $schema): Schema
    {
        $durations = [15 => '15 min', 30 => '30 min', 45 => '45 min', 60 => '60 min', 90 => '90 min', 120 => '120 min', 150 => '150 min', 180 => '180 min'];

        // a place may have many courts of one kind (ping-pong tables): players book "a table", the place gets any free one
        return $schema->columns(2)->components([
            Select::make('size')->label(__('admin.field.size'))->options(Options::fieldSizes())->required()->live(),
            TextInput::make('name')->label(__('admin.field.name'))->maxLength(40)->placeholder(__('admin.field.name_ph')),
            // a surface only for football fields (a ping-pong table has none)
            Select::make('surface')->label(__('admin.field.surface'))->options(['turf' => __('common.surfaces.turf'), 'grass' => __('common.surfaces.grass')])->default('turf')
                ->visible(fn ($get) => ($get('size') instanceof FieldSize ? $get('size')->value : $get('size')) !== 'table')
                ->required(fn ($get) => ($get('size') instanceof FieldSize ? $get('size')->value : $get('size')) !== 'table'),
            CheckboxList::make('durations')->label(__('admin.field.durations'))->options($durations)->columns(5)->required()->columnSpanFull(),
            Select::make('default_duration')->label(__('admin.field.default_duration'))->options($durations)->required(),
            TextInput::make('sort')->label(__('admin.field.sort'))->integer()->default(0),
            Toggle::make('indoor')->label(__('admin.field.indoor')),
            Toggle::make('active')->label(__('admin.field.active'))->default(true),
        ]);
    }

    /** format and max_players follow from the size; durations are stored as integers. */
    public static function clean(array $data): array
    {
        $size = FieldSize::from($data['size']);
        $data['format'] = $size->format();
        $data['max_players'] = $size->maxPlayers();
        $data['durations'] = collect($data['durations'] ?? [])->map(fn ($d) => (int) $d)->sort()->values()->all();
        $data['default_duration'] = (int) $data['default_duration'];
        if (! in_array($data['default_duration'], $data['durations'], true)) {
            $data['durations'] = collect([...$data['durations'], $data['default_duration']])->sort()->values()->all();
        }

        return $data;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (Field $f) => $f->displayName() ?: __('telegram.field.'.$f->size->value))
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label(__('admin.field.name'))->placeholder('—')->weight('bold'),
                TextColumn::make('size')->label(__('admin.field.size'))->formatStateUsing(fn ($state) => __('telegram.field.'.($state instanceof FieldSize ? $state->value : $state))),
                TextColumn::make('surface')->label(__('admin.field.surface'))->formatStateUsing(fn ($state, Field $record) => $record->size === FieldSize::Table ? '—' : __('common.surfaces.'.$state)),
                IconColumn::make('indoor')->label(__('admin.field.indoor'))->boolean()->falseColor('gray'),
                TextColumn::make('durations_text')->label(__('admin.field.durations'))->getStateUsing(fn (Field $record) => implode(' / ', $record->durations ?? []).' min'),
                TextColumn::make('default_duration')->label(__('admin.field.default_duration'))->suffix(' min'),
                IconColumn::make('active')->label(__('admin.field.active'))->boolean(),
            ])
            ->headerActions([
                // several courts of a kind at once, copying the first one (lengths, prices): "Add 2 tables"
                Action::make('addMany')->label(__('admin.field.add_many'))->icon('heroicon-o-plus-circle')->color('gray')
                    ->visible(fn () => $this->getOwnerRecord()->fields()->exists())
                    ->schema([
                        Select::make('size')->label(__('admin.field.size'))->required()
                            ->options(fn () => collect(Options::fieldSizes())->only($this->getOwnerRecord()->fields()->pluck('size')->map(fn ($s) => $s instanceof FieldSize ? $s->value : $s)->unique()->all())->all()),
                        TextInput::make('count')->label(__('admin.field.how_many'))->integer()->minValue(1)->maxValue(20)->default(1)->required(),
                    ])
                    ->action(function (array $data) {
                        for ($i = 0; $i < (int) $data['count']; $i++) {
                            app(Courts::class)->add($this->getOwnerRecord(), FieldSize::from($data['size']));
                        }
                    }),
                CreateAction::make()->mutateDataUsing(fn (array $data) => self::clean($data)),
            ])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data) => self::clean($data)),
                DeleteAction::make(),
            ]);
    }
}
