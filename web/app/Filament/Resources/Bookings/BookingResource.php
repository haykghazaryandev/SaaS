<?php

namespace App\Filament\Resources\Bookings;

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\BookingException;
use App\Enums\BookingStatus;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Venues\VenueResource;
use App\Filament\Support\Options;
use App\Models\Booking;
use App\Support\LocalTime;
use App\Support\Phone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.bookings');
    }

    public static function getModelLabel(): string
    {
        return __('admin.booking.one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.booking.many');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Booking::where('status', 'pending')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('admin.dashboard.pending');
    }

    public static function statusColor(BookingStatus|string|null $s): string
    {
        return match ($s instanceof BookingStatus ? $s->value : $s) {
            'pending' => 'warning', 'accepted' => 'success', 'declined', 'cancelled' => 'danger', 'no_show' => 'danger', default => 'gray',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (Booking $record) => $record->code)->columns(3)->schema([
                TextEntry::make('status')->label(__('admin.booking.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('telegram.status.'.$state->value))->color(fn ($state) => self::statusColor($state)),
                TextEntry::make('venue.name')->label(__('admin.venue.one'))
                    ->url(fn (Booking $record) => VenueResource::getUrl('edit', ['record' => $record->venue])),
                TextEntry::make('field.size')->label(__('admin.field.one'))->formatStateUsing(fn ($state) => __('telegram.field.'.$state->value)),
                TextEntry::make('starts_at')->label(__('admin.booking.when'))
                    ->formatStateUsing(fn (Booking $record) => Options::local($record->starts_at).'–'.Options::local($record->ends_at, 'H:i')),
                TextEntry::make('price')->label(__('admin.booking.price'))->formatStateUsing(fn ($state) => Options::amd($state)),
                TextEntry::make('source')->label(__('admin.booking.source'))->formatStateUsing(fn ($state) => __('admin.booking.source_'.$state->value)),
            ]),
            Section::make(__('admin.booking.contact'))->columns(3)->schema([
                TextEntry::make('contact_name')->label(__('admin.user.name')),
                TextEntry::make('contact_phone')->label(__('admin.user.phone'))->formatStateUsing(fn ($state) => Phone::format($state))->placeholder('—')->copyable(),
                TextEntry::make('user.name')->label(__('admin.user.one'))->placeholder('—')
                    ->url(fn (Booking $record) => $record->user ? UserResource::getUrl('view', ['record' => $record->user]) : null),
                TextEntry::make('team_name')->label(__('admin.booking.team'))->placeholder('—'),
                TextEntry::make('note_player')->label(__('admin.booking.note_player'))->placeholder('—'),
                TextEntry::make('note_owner')->label(__('admin.booking.note_owner'))->placeholder('—'),
                TextEntry::make('decline_reason')->label(__('admin.booking.decline_reason'))->placeholder('—'),
                TextEntry::make('cancel_reason')->label(__('admin.booking.cancel_reason'))->placeholder('—')
                    ->suffix(fn (Booking $record) => $record->cancelled_by ? ' ('.$record->cancelled_by.')' : ''),
            ]),
            Section::make(__('admin.booking.history'))->collapsible()->schema([
                RepeatableEntry::make('events')->hiddenLabel()->columns(3)->schema([
                    TextEntry::make('type')->hiddenLabel()->badge(),
                    TextEntry::make('created_at')->hiddenLabel()->dateTime('d.m.Y H:i', 'Asia/Yerevan'),
                    TextEntry::make('changes')->hiddenLabel()->placeholder('')
                        ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_UNESCAPED_UNICODE) : (string) $state),
                ]),
            ]),
        ]);
    }

    /**
     * Default order: what will happen next first (requests and confirmed bookings not over yet, soonest on top),
     * then everything else: past, cancelled, declined… (newest on top).
     */
    public static function soonestFirst(Builder $query): Builder
    {
        $next = "ends_at >= now() and status in ('pending', 'accepted')";

        return $query->orderByRaw("case when {$next} then 0 else 1 end")
            ->orderByRaw("case when {$next} then starts_at end asc")
            ->orderBy('starts_at', 'desc');
    }

    /** Columns shared by the bookings list and the user's bookings. */
    public static function columns(bool $withVenue = true): array
    {
        return array_values(array_filter([
            TextColumn::make('code')->label(__('admin.booking.code'))->searchable()->weight('bold')->copyable(),
            $withVenue ? TextColumn::make('venue.name')->label(__('admin.venue.one'))->sortable() : null,
            TextColumn::make('field.size')->label(__('admin.field.one'))->badge()->color('gray')
                ->formatStateUsing(fn ($state) => $state->format()),
            TextColumn::make('starts_at')->label(__('admin.booking.when'))->sortable()
                ->formatStateUsing(fn (Booking $record) => Options::local($record->starts_at).'–'.Options::local($record->ends_at, 'H:i')),
            TextColumn::make('contact_name')->label(__('admin.booking.contact'))->searchable()
                ->description(fn (Booking $record) => Phone::format($record->contact_phone)),
            TextColumn::make('price')->label(__('admin.booking.price'))->formatStateUsing(fn ($state) => Options::amd($state))->sortable()->alignEnd(),
            TextColumn::make('status')->label(__('admin.booking.status'))->badge()
                ->formatStateUsing(fn ($state) => __('telegram.status.'.$state->value))->color(fn ($state) => self::statusColor($state)),
            TextColumn::make('source')->label(__('admin.booking.source'))->formatStateUsing(fn ($state) => __('admin.booking.source_'.$state->value))
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('created_at')->label(__('admin.booking.created'))->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
        ]));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('venue', 'field', 'user'))
            ->defaultSort(fn (Builder $query) => BookingResource::soonestFirst($query))
            ->columns(self::columns())
            ->filters([
                SelectFilter::make('venue_id')->label(__('admin.venue.one'))->relationship('venue', 'name')->searchable()->preload(),
                SelectFilter::make('status')->label(__('admin.booking.status'))->options(Options::bookingStatuses())->multiple(),
                Filter::make('day')->label(__('admin.booking.day'))
                    ->schema([DatePicker::make('day')->label(__('admin.booking.day'))->native(false)])
                    ->query(function (Builder $q, array $data) {
                        if (! ($data['day'] ?? null)) {
                            return $q;
                        }
                        $day = LocalTime::day($data['day']);

                        return $q->where('starts_at', '>=', $day->utc())->where('starts_at', '<', $day->addDay()->utc());
                    })
                    ->indicateUsing(fn (array $data) => ($data['day'] ?? null) ? __('admin.booking.day').': '.$data['day'] : null),
                Filter::make('upcoming')->label(__('admin.booking.upcoming'))->query(fn (Builder $query) => $query->where('starts_at', '>=', now())),
            ])
            ->recordActions([
                ViewAction::make(),
                self::cancelAction(),
            ]);
    }

    /** Cancel by the system (admin): the player gets a Telegram message. */
    public static function cancelAction(): Action
    {
        return Action::make('cancel')->label(__('admin.booking.cancel'))->icon('heroicon-o-x-circle')->color('danger')
            ->visible(fn (Booking $record) => $record->status->holdsTime() && $record->starts_at->isFuture())
            ->requiresConfirmation()
            ->modalDescription(__('admin.booking.cancel_help'))
            ->schema([TextInput::make('reason')->label(__('admin.booking.cancel_reason'))->maxLength(200)])
            ->action(function (Booking $record, array $data, CancelBooking $cancel) {
                try {
                    $cancel->handle($record, auth()->user(), 'system', $data['reason'] ?? null);
                    Notification::make()->success()->title(__('admin.booking.cancelled', ['code' => $record->code]))->send();
                } catch (BookingException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBookings::route('/'),
            'view' => ViewBooking::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
