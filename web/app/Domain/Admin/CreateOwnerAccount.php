<?php

namespace App\Domain\Admin;

use App\Enums\VenueRole;
use App\Models\User;
use App\Models\Venue;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin only: give a phone number access to a place as owner or staff.
 * Finds the user by phone (or creates a registered one), then adds or updates the venue_users row.
 * The person then logs in at /owner with that phone and a Telegram code.
 */
class CreateOwnerAccount
{
    public function handle(User $admin, string $phone, ?string $name, Venue $venue, VenueRole $role): User
    {
        $normalized = Phone::normalize($phone);
        if (! $normalized) {
            throw ValidationException::withMessages(['phone' => __('auth.phone.invalid')]);
        }

        return DB::transaction(function () use ($admin, $normalized, $name, $venue, $role) {
            $user = User::withTrashed()->firstOrNew(['phone' => $normalized]);
            if ($user->trashed()) {
                $user->restore();
            }
            $name = trim((string) $name);
            $user->forceFill([
                'name' => $user->name ?: ($name ?: null),
                'locale' => $user->locale ?? 'hy',
                'registered_at' => $user->registered_at ?? now(),
            ])->save();

            $exists = DB::table('venue_users')->where('venue_id', $venue->id)->where('user_id', $user->id)->exists();
            if ($exists) {
                DB::table('venue_users')->where('venue_id', $venue->id)->where('user_id', $user->id)
                    ->update(['role' => $role->value, 'updated_at' => now()]);
            } else {
                $venue->staff()->attach($user->id, ['role' => $role->value, 'created_by' => $admin->id]);
            }

            return $user;
        });
    }
}
