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
 * The people who run a place: one owner (everything; the owner can also work in the staff panel) and staff (the daily
 * work: Now, Today, Schedule, players, a scanned card, running tournaments, chats). The owner adds staff by name and
 * phone (a new number gets an account: logging in with it opens the staff panel) or takes them off. The owner is set
 * by ZoneSpot (admin), never from here.
 */
class Team
{
    /** Staff by phone (and a name for a new account). */
    public function add(Venue $venue, string $phone, ?string $name, User $by): User
    {
        $normal = Phone::normalize($phone);
        if (! $normal) {
            throw new BookingException('bad_phone');
        }

        return DB::transaction(function () use ($venue, $normal, $name, $by) {
            $user = User::firstOrCreate(['phone' => $normal], ['name' => trim((string) $name) ?: null, 'locale' => $by->locale ?? 'hy']);
            $was = $venue->staff()->whereKey($user->id)->first()?->pivot->role;
            if ($was === VenueRole::Owner->value) {
                throw new BookingException('team_is_owner');
            }
            if (! $user->name && trim((string) $name) !== '') {
                $user->update(['name' => trim((string) $name)]);
            }
            $venue->staff()->syncWithoutDetaching([$user->id => ['role' => VenueRole::Staff->value]]);
            if (! $was) {
                DB::afterCommit(fn () => $user->notify(new Notice('team_added', ['venue' => $venue->name, 'role' => __('owner.team.role_staff')], '/owner')));
            }

            return $user;
        });
    }

    public function remove(Venue $venue, User $user, User $by): void
    {
        if ($user->id === $by->id || $venue->staff()->whereKey($user->id)->first()?->pivot->role === VenueRole::Owner->value) {
            throw new BookingException('team_self');
        }
        $venue->staff()->detach($user->id);
    }
}
