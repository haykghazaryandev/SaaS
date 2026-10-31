<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /** is_admin is not mass-assignable (on purpose): the admin switch is saved here, never for yourself. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $admin = $data['is_admin'] ?? null;
        unset($data['is_admin']);
        $record->update($data);
        if ($admin !== null && $record->getKey() !== auth()->id()) {
            $record->forceFill(['is_admin' => (bool) $admin])->save();
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return UserResource::getUrl('view', ['record' => $this->record]);
    }
}
