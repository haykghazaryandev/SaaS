<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\BookingsRelationManager;
use App\Models\User;
use App\Support\Phone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.people');
    }

    public static function getModelLabel(): string
    {
        return __('admin.user.one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.user.many');
    }

    public static function getGlobalSearchResultTitle($record): string
    {
        return ($record->name ?: '—').' · '.Phone::format($record->phone);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'phone', 'username'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label(__('admin.user.name'))->maxLength(60),
                TextInput::make('username')->label(__('admin.user.username'))->maxLength(32)->regex('/^[a-z0-9_.]+$/')->unique(ignoreRecord: true),
                Select::make('locale')->label(__('admin.user.locale'))->options(['hy' => 'Հայերեն', 'ru' => 'Русский', 'en' => 'English'])->required(),
                Textarea::make('bio')->label(__('admin.user.bio'))->maxLength(300)->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.user.profile'))->columns(3)->schema([
                TextEntry::make('name')->label(__('admin.user.name'))->placeholder('—'),
                TextEntry::make('phone')->label(__('admin.user.phone'))->formatStateUsing(fn ($state) => Phone::format($state))->copyable(),
                TextEntry::make('username')->label(__('admin.user.username'))->placeholder('—')->prefix('@'),
                TextEntry::make('locale')->label(__('admin.user.locale')),
                TextEntry::make('registered_at')->label(__('admin.user.registered'))->dateTime('d.m.Y H:i', 'Asia/Yerevan')->placeholder(__('admin.user.guest')),
                TextEntry::make('last_seen_at')->label(__('admin.user.last_seen'))->since()->placeholder('—'),
            ]),
            Section::make(__('admin.user.safety'))->columns(3)->schema([
                TextEntry::make('status')->label(__('admin.user.status'))->badge()
                    ->getStateUsing(fn (User $record) => $record->isBlocked() ? 'blocked' : 'active')
                    ->formatStateUsing(fn ($state) => __('admin.user.status_'.$state))
                    ->color(fn ($state) => $state === 'blocked' ? 'danger' : 'success'),
                TextEntry::make('blocked_until')->label(__('admin.user.blocked_until'))->dateTime('d.m.Y H:i', 'Asia/Yerevan')->placeholder('—'),
                TextEntry::make('no_show_count')->label(__('admin.user.no_shows')),
                TextEntry::make('bookings_count')->label(__('admin.user.bookings'))->getStateUsing(fn (User $record) => $record->bookings()->count()),
                IconEntry::make('telegram')->label('Telegram')->boolean()->falseColor('gray')->getStateUsing(fn (User $record) => $record->hasTelegram()),
                TextEntry::make('telegram_linked_at')->label(__('admin.user.telegram_linked'))->dateTime('d.m.Y H:i', 'Asia/Yerevan')->placeholder('—'),
                TextEntry::make('places')->label(__('admin.user.places'))->columnSpanFull()->placeholder('—')
                    ->getStateUsing(fn (User $record) => $record->venues()->get()->map(fn ($v) => $v->name.' ('.__('admin.staff.role_'.$v->pivot->role).')')->implode(', ')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('bookings')->with('venues'))
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder(__('admin.user.search'))
            ->splitSearchTerms(false)
            ->columns([
                TextColumn::make('name')->label(__('admin.user.name'))->weight('bold')->placeholder('—')->sortable()
                    ->description(fn (User $u) => $u->username ? '@'.$u->username : null)
                    ->searchable(query: fn (Builder $query, string $search) => self::search($query, $search)),
                TextColumn::make('phone')->label(__('admin.user.phone'))->formatStateUsing(fn ($state) => Phone::format($state))->copyable(),
                TextColumn::make('role')->label(__('admin.user.role'))->badge()
                    ->getStateUsing(fn (User $u) => $u->is_admin ? 'admin' : ($u->venues->isNotEmpty() ? 'owner' : ($u->isRegistered() ? 'player' : 'guest')))
                    ->formatStateUsing(fn ($state) => __('admin.user.role_'.$state))
                    ->color(fn ($state) => match ($state) {
                        'admin' => 'danger', 'owner' => 'primary', 'player' => 'gray', default => 'warning'
                    }),
                TextColumn::make('bookings_count')->label(__('admin.user.bookings'))->sortable()->alignCenter(),
                TextColumn::make('no_show_count')->label(__('admin.user.no_shows'))->sortable()->alignCenter()
                    ->color(fn ($state) => $state > 0 ? 'danger' : null),
                IconColumn::make('telegram')->label('Telegram')->boolean()->falseColor('gray')->getStateUsing(fn (User $u) => $u->hasTelegram()),
                TextColumn::make('status')->label(__('admin.user.status'))->badge()
                    ->getStateUsing(fn (User $u) => $u->isBlocked() ? 'blocked' : 'active')
                    ->formatStateUsing(fn ($state) => __('admin.user.status_'.$state))
                    ->color(fn ($state) => $state === 'blocked' ? 'danger' : 'success'),
                TextColumn::make('created_at')->label(__('admin.user.created'))->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.user.status'))->options(['active' => __('admin.user.status_active'), 'blocked' => __('admin.user.status_blocked')]),
                TernaryFilter::make('telegram')->label('Telegram')->nullable()->attribute('telegram_chat_id'),
                Filter::make('owners')->label(__('admin.user.only_owners'))->query(fn (Builder $query) => $query->has('venues')),
                Filter::make('no_shows')->label(__('admin.user.with_no_shows'))->query(fn (Builder $query) => $query->where('no_show_count', '>', 0)),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make([
                    EditAction::make(),
                    ...self::moderationActions(),
                ]),
            ]);
    }

    /** "+374 91…", "091…" or "91 23" find by phone; other text by name / username. */
    public static function search(Builder $q, string $search): Builder
    {
        $digits = preg_replace('/\D+/', '', $search);

        return $q->where(function (Builder $w) use ($search, $digits) {
            $w->where('name', 'ilike', '%'.$search.'%')->orWhere('username', 'ilike', '%'.ltrim($search, '@').'%');
            if (strlen($digits) >= 3) {
                $tail = preg_replace('/^(374|0)/', '', $digits);
                $w->orWhere('phone', 'like', '%'.$tail.'%');
            }
        });
    }

    /** Block / unblock and reset no-shows (used in the table and on the user page). */
    public static function moderationActions(): array
    {
        return [
            Action::make('block')->label(__('admin.user.block'))->icon('heroicon-o-no-symbol')->color('danger')
                ->visible(fn (User $record) => ! $record->isBlocked() && ! $record->is_admin)
                ->schema([
                    DateTimePicker::make('until')->label(__('admin.user.block_until'))->helperText(__('admin.user.block_until_help'))
                        ->seconds(false)->timezone('Asia/Yerevan')->minDate(now()),
                ])
                ->requiresConfirmation()
                ->action(function (User $record, array $data) {
                    $record->forceFill($data['until'] ?? null
                        ? ['status' => 'active', 'blocked_until' => $data['until']]
                        : ['status' => 'blocked', 'blocked_until' => null])->save();
                    Notification::make()->success()->title(__('admin.user.blocked_done'))->send();
                }),
            Action::make('unblock')->label(__('admin.user.unblock'))->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn (User $record) => $record->isBlocked())
                ->requiresConfirmation()
                ->action(function (User $record) {
                    $record->forceFill(['status' => 'active', 'blocked_until' => null])->save();
                    Notification::make()->success()->title(__('admin.user.unblocked_done'))->send();
                }),
            // testing registration again with the same phone; also for "delete my data" requests
            Action::make('erase')->label(__('admin.user.erase'))->icon('heroicon-o-trash')->color('danger')
                ->requiresConfirmation()
                ->modalHeading(fn (User $record) => __('admin.user.erase_title', ['name' => $record->name ?: $record->phone]))
                ->modalDescription(__('admin.user.erase_help'))
                ->modalSubmitActionLabel(__('admin.user.erase'))
                ->action(function (User $record, Action $action) {
                    $self = $record->is(auth()->user());
                    app(\App\Domain\Auth\AccountEraser::class)->erase($record);
                    Notification::make()->success()->title(__('admin.user.erased'))->send();
                    if ($self) {
                        auth()->guard('web')->logout();
                        request()->session()->invalidate();
                        $action->redirect(route('login'));
                    } else {
                        $action->redirect(UserResource::getUrl('index'));
                    }
                }),
            Action::make('resetNoShows')->label(__('admin.user.reset_no_shows'))->icon('heroicon-o-arrow-path')->color('warning')
                ->visible(fn (User $record) => $record->no_show_count > 0 || $record->blocked_until || $record->booking_ban_until)
                ->requiresConfirmation()
                ->modalDescription(__('admin.user.reset_no_shows_help'))
                ->action(function (User $record) {
                    app(\App\Domain\Booking\AfterBooking\Penalties::class)->reset($record);
                    $record->forceFill(['blocked_until' => null])->save();
                    Notification::make()->success()->title(__('admin.user.reset_done'))->send();
                }),
        ];
    }

    public static function getRelations(): array
    {
        return [
            BookingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
