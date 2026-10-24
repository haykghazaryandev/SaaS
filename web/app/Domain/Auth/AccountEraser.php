<?php

namespace App\Domain\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a person completely (not the soft delete): friends, game places, chat membership, alerts,
 * logins and notices go with them (database cascades). Their bookings stay for the place's records,
 * without the account. Used to test registration again with the same phone.
 */
class AccountEraser
{
    public function erase(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->notifications()->delete();   // database notices are not a foreign key
            $user->forceDelete();               // the photo (user_photos) goes with it
        });
    }
}
