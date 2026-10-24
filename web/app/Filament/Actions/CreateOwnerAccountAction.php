<?php

namespace App\Filament\Actions;

use App\Domain\Admin\CreateOwnerAccount;
use App\Enums\VenueRole;
use App\Filament\Resources\Venues\RelationManagers\StaffRelationManager;
use App\Models\Venue;
use App\Support\Phone;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * "Create owner account": phone + name + place + role. Finds or creates the user and gives access to the place.
 * The owner then logs in at /owner with that phone and a Telegram code.
 */
class CreateOwnerAccountAction
{
    public static function make(string $name = 'createOwner'): Action
    {
        return Action::make($name)
            ->label(__('admin.owner.create'))
            ->icon('heroicon-o-key')
            ->modalHeading(__('admin.owner.create'))
            ->modalDescription(__('admin.owner.help'))
            ->modalSubmitActionLabel(__('admin.owner.submit'))
            ->schema(self::schema())
            ->action(function (array $data, CreateOwnerAccount $create) {
                $user = $create->handle(auth()->user(), $data['phone'], $data['name'] ?? null, Venue::findOrFail($data['venue_id']), VenueRole::from($data['role']));
                Notification::make()->success()->title(__('admin.owner.created', ['phone' => Phone::format($user->phone)]))->send();
            });
    }

    public static function schema(): array
    {
        return [
            TextInput::make('phone')->label(__('common.phone'))->tel()->required()->placeholder('+374 91 234 567')
                ->rule(fn () => function ($attr, $value, $fail) {
                    if (! Phone::normalize($value)) {
                        $fail(__('auth.phone.invalid'));
                    }
                }),
            TextInput::make('name')->label(__('common.name'))->maxLength(60),
            Select::make('venue_id')->label(__('common.place'))->options(fn () => Venue::orderBy('name')->pluck('name', 'id'))->searchable()->required(),
            Select::make('role')->label(__('common.role'))->options(StaffRelationManager::roles())->default('owner')->required(),
        ];
    }
}
