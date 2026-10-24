<?php

namespace App\Filament\Resources\Venues\RelationManagers;

use App\Domain\Admin\CreateOwnerAccount;
use App\Enums\VenueRole;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Phone;
use Filament\Actions\Action;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Owners and staff of the place (venue_users). Owner accounts are created only here / on "Owner account". */
class StaffRelationManager extends RelationManager
{
    protected static string $relationship = 'staff';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('common.owners_and_staff');
    }

    public static function getModelLabel(): string
    {
        return __('admin.staff.one');
    }

    public static function roles(): array
    {
        return ['owner' => __('admin.staff.role_owner'), 'staff' => __('admin.staff.role_staff')];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label(__('common.name'))->weight('bold')->placeholder('—')
                    ->url(fn (User $record) => UserResource::getUrl('view', ['record' => $record])),
                TextColumn::make('phone')->label(__('common.phone'))->formatStateUsing(fn ($state) => Phone::format($state)),
                TextColumn::make('pivot.role')->label(__('common.role'))->badge()
                    ->formatStateUsing(fn ($state) => self::roles()[$state] ?? $state)
                    ->color(fn ($state) => $state === 'owner' ? 'primary' : 'gray'),
                IconColumn::make('telegram_chat_id')->label('Telegram')->boolean()->falseColor('gray')->getStateUsing(fn (User $record) => $record->telegram_chat_id !== null),
                TextColumn::make('last_seen_at')->label(__('admin.user.last_seen'))->since()->placeholder('—'),
            ])
            ->headerActions([
                Action::make('addStaff')->label(__('admin.staff.add'))->icon('heroicon-o-user-plus')
                    ->schema([
                        TextInput::make('phone')->label(__('common.phone'))->tel()->required()->placeholder('+374 91 234 567')
                            ->rule(fn () => function ($attr, $value, $fail) {
                                if (! Phone::normalize($value)) {
                                    $fail(__('auth.phone.invalid'));
                                }
                            }),
                        TextInput::make('name')->label(__('common.name'))->maxLength(60),
                        Select::make('role')->label(__('common.role'))->options(self::roles())->default('owner')->required(),
                    ])
                    ->action(function (array $data, CreateOwnerAccount $create) {
                        $user = $create->handle(auth()->user(), $data['phone'], $data['name'] ?? null, $this->getOwnerRecord(), VenueRole::from($data['role']));
                        Notification::make()->success()->title(__('admin.owner.created', ['phone' => Phone::format($user->phone)]))->send();
                    }),
            ])
            ->recordActions([
                Action::make('role')->label(__('admin.staff.change_role'))->icon('heroicon-o-arrows-right-left')->color('gray')
                    ->schema([Select::make('role')->label(__('common.role'))->options(self::roles())->required()])
                    ->fillForm(fn (User $record) => ['role' => $record->pivot->role])
                    ->action(fn (User $record, array $data) => $this->getOwnerRecord()->staff()->updateExistingPivot($record->id, ['role' => $data['role']])),
                DetachAction::make()->label(__('admin.staff.remove')),
            ]);
    }
}
