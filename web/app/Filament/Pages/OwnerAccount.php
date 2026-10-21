<?php

namespace App\Filament\Pages;

use App\Domain\Admin\CreateOwnerAccount;
use App\Enums\VenueRole;
use App\Filament\Actions\CreateOwnerAccountAction;
use App\Models\Venue;
use App\Support\Phone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Admin page "Create owner account" (plan §9.3): phone + name, place + role.
 * The owner then logs in at /owner with this phone and a Telegram code.
 */
class OwnerAccount extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'owner-account';

    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.people');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.owner.create');
    }

    public function getTitle(): string
    {
        return __('admin.owner.create');
    }

    public function mount(): void
    {
        $this->form->fill(['role' => 'owner']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components(CreateOwnerAccountAction::schema());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->description(__('admin.owner.help'))->schema([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('create')
                    ->footer([
                        Actions::make([
                            Action::make('create')->label(__('admin.owner.submit'))->submit('create'),
                        ]),
                    ]),
            ]),
        ]);
    }

    public function create(CreateOwnerAccount $create): void
    {
        $data = $this->form->getState();
        $user = $create->handle(auth()->user(), $data['phone'], $data['name'] ?? null, Venue::findOrFail($data['venue_id']), VenueRole::from($data['role']));
        Notification::make()->success()->title(__('admin.owner.created', ['phone' => Phone::format($user->phone)]))
            ->body(__('admin.owner.next'))->send();
        $this->form->fill(['role' => 'owner']);
    }
}
