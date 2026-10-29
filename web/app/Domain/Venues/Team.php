<?php

namespace App\Domain\Venues;

use App\Domain\Booking\BookingException;
use App\Enums\VenueRole;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\Notice;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;

/**
 * The people who run a place: owners (everything) and staff (the daily work: Now, Today, Schedule, players, a scanned
 * card, running tournaments, chats). The owner adds someone by phone (a new number gets an account, they log in with
 * it), changes their role or takes them off; a place always keeps at least one owner, and nobody removes themselves.
 */
class Team
{
    public function add(Venue $venue, string $phone, string $role, ?string $name, User $by): User
    {
        $normal = Phone::normalize($phone);
        if (! $normal) {
            throw new BookingException('bad_phone');
        }
        $role = VenueRole::from($role)->value;

        return DB::transaction(function () use ($venue, $normal, $role, $name, $by) {
            $user = User::firstOrCreate(['phone' => $normal], ['name' => trim((string) $name) ?: null, 'locale' => $by->locale ?? 'hy']);
            $was = $venue->staff()->whereKey($user->id)->first()?->pivot->role;
            if ($was === 'owner' && $role !== 'owner') {
                $this->keepAnOwner($venue, $user);
            }
            $venue->staff()->syncWithoutDetaching([$user->id => ['role' => $role]]);
            if (! $was) {
                DB::afterCommit(fn () => $user->notify(new Notice('team_added', ['venue' => $venue->name, 'role' => __('owner.team.role_'.$role)], '/owner')));
            }

            return $user;
        });
    }

    public function remove(Venue $venue, User $user, User $by): void
    {
        if ($user->id === $by->id) {
            throw new BookingException('team_self');
        }
        if ($venue->staff()->whereKey($user->id)->first()?->pivot->role === 'owner') {
            $this->keepAnOwner($venue, $user);
        }
        $venue->staff()->detach($user->id);
    }

    private function keepAnOwner(Venue $venue, User $leaving): void
    {
        if (! $venue->staff()->wherePivot('role', 'owner')->whereKeyNot($leaving->id)->exists()) {
            throw new BookingException('team_last_owner');
        }
    }
}
