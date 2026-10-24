<?php

namespace App\Filament\Resources\Venues;

use App\Filament\Resources\Venues\Pages\CreateVenue;
use App\Filament\Resources\Venues\Pages\EditVenue;
use App\Filament\Resources\Venues\Pages\ListVenues;
use App\Filament\Resources\Venues\RelationManagers\FieldsRelationManager;
use App\Filament\Resources\Venues\RelationManagers\OpeningHoursRelationManager;
use App\Filament\Resources\Venues\RelationManagers\PriceRulesRelationManager;
use App\Filament\Resources\Venues\RelationManagers\StaffRelationManager;
use App\Filament\Support\Options;
use App\Models\Venue;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

class VenueResource extends Resource
{
    protected static ?string $model = Venue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.places');
    }

    public static function getModelLabel(): string
    {
        return __('common.place');
    }

    public static function getPluralModelLabel(): string
    {
        return __('common.places');
    }

    public static function form(Schema $schema): Schema
    {
        $langs = ['hy' => 'Հայերեն', 'ru' => 'Русский', 'en' => 'English'];

        return $schema->columns(1)->components([
            Section::make(__('admin.venue.main'))->columns(2)->schema([
                TextInput::make('name')->label(__('admin.venue.name'))->required()->maxLength(120)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $set, callable $get, string $operation) => $operation === 'create' && ! $get('slug') ? $set('slug', Str::slug($state)) : null),
                TextInput::make('slug')->label(__('admin.venue.slug'))->required()->maxLength(80)
                    ->regex('/^[a-z0-9-]+$/')->unique(ignoreRecord: true)->helperText(__('admin.venue.slug_help')),
                Select::make('district')->label(__('admin.venue.district'))->options(Options::districts())->required()->searchable(),
                Select::make('status')->label(__('common.status'))->options(Options::venueStatuses())->default('active')->required(),
                TextInput::make('phone')->label(__('common.phone'))->tel()->maxLength(20),
                TextInput::make('lat')->label(__('admin.venue.lat'))->numeric()->minValue(39)->maxValue(42),
                TextInput::make('lng')->label(__('admin.venue.lng'))->numeric()->minValue(43)->maxValue(47),
            ]),
            Section::make(__('admin.venue.texts'))->schema([
                Tabs::make('langs')->tabs(collect($langs)->map(fn ($label, $l) => Tab::make($label)->schema([
                    TextInput::make("address.$l")->label(__('common.address'))->required($l === 'hy')->maxLength(200),
                    Textarea::make("description.$l")->label(__('admin.venue.description'))->rows(3)->maxLength(1000),
                ]))->values()->all()),
            ]),
            Section::make(__('admin.venue.amenities'))->schema([
                CheckboxList::make('amenities')->hiddenLabel()->options(Options::amenities())->columns(3),
            ]),
            Section::make(__('common.booking_rules'))->description(__('admin.venue.rules_help'))->columns(3)->collapsible()->schema([
                ...collect(['horizon_days', 'min_notice_min', 'free_cancel_hours', 'request_expire_min', 'max_pending_per_phone', 'step_min'])
                    ->map(fn ($k) => TextInput::make("rules.$k")->label(__('admin.rule.'.$k))->integer()->minValue(0)
                        ->placeholder((string) Venue::DEFAULT_RULES[$k]))->all(),
                Toggle::make('rules.auto_accept_regulars')->label(__('admin.rule.auto_accept_regulars')),
            ]),
        ]);
    }

    /** Empty rule inputs mean "use the default"; numbers are stored as integers. */
    public static function cleanData(array $data): array
    {
        $rules = [];
        foreach ($data['rules'] ?? [] as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $rules[$k] = is_bool($v) ? $v : (int) $v;
        }
        if (($rules['auto_accept_regulars'] ?? false) === false) {
            unset($rules['auto_accept_regulars']);
        }
        $data['rules'] = $rules;
        $data['amenities'] = array_values($data['amenities'] ?? []);
        foreach (['address', 'description'] as $f) {
            $data[$f] = array_filter($data[$f] ?? [], fn ($v) => $v !== null && $v !== '') ?: ($f === 'address' ? [] : null);
        }

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['fields', 'staff', 'bookings as pending_count' => fn ($b) => $b->where('status', 'pending')]))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('admin.venue.name'))->searchable()->sortable()->weight('bold')
                    ->description(fn (Venue $v) => $v->tr('address', 'en')),
                TextColumn::make('district')->label(__('admin.venue.district'))->formatStateUsing(fn ($state) => __('common.districts.'.$state))->sortable(),
                TextColumn::make('status')->label(__('common.status'))->badge()
                    ->formatStateUsing(fn ($state) => Options::venueStatuses()[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success', 'paused' => 'warning', default => 'gray'
                    }),
                TextColumn::make('fields_count')->label(__('common.fields'))->alignCenter(),
                TextColumn::make('staff_count')->label(__('admin.venue.staff_count'))->alignCenter(),
                TextColumn::make('pending_count')->label(__('admin.venue.pending_count'))->alignCenter()->badge()
                    ->color(fn ($state) => $state ? 'warning' : 'gray'),
                TextColumn::make('rating_avg')->label('★')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('common.status'))->options(Options::venueStatuses()),
                SelectFilter::make('district')->label(__('admin.venue.district'))->options(Options::districts()),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            FieldsRelationManager::class,
            PriceRulesRelationManager::class,
            OpeningHoursRelationManager::class,
            StaffRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVenues::route('/'),
            'create' => CreateVenue::route('/create'),
            'edit' => EditVenue::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
