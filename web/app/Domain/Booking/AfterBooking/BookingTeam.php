<?php

namespace App\Domain\Booking\AfterBooking;

use App\Domain\Booking\BookingException;
use App\Models\Booking;
use App\Models\BookingPlayer;
use App\Models\User;
use App\Notifications\Notice;
use App\Support\Phone;
use Illuminate\Support\Str;

/**
 * The team of a booking: the booker adds friends (or invites a phone number, or shares the invite link),
 * and those people get the booking's news and reminders too.
 * - friends are added at once and told (bell + Telegram);
 * - a phone with an account is added the same way; a phone without one waits: when that person logs in with
 *   that number (confirmed by Telegram), they join automatically;
 * - anyone who opens the invite link /join/{token} while logged in joins.
 * Up to the field's player count (10 for 5×5, 22 for 11×11).
 */
class BookingTeam
{
    /** @param int[] $userIds friends of the booker */
    public function addFriends(Booking $b, User $by, array $userIds): int
    {
        $this->ensureBooker($b, $by);
        $friends = collect($by->friendIds());
        $n = 0;
        foreach (User::whereIn('id', array_intersect($userIds, $friends->all()))->get() as $friend) {
            $n += $this->add($b, $friend, $by) ? 1 : 0;
        }

        return $n;
    }

    /** Returns ['added', User] or ['invited', null] (nobody has that number yet: share the link). */
    public function invitePhone(Booking $b, User $by, string $phone): array
    {
        $this->ensureBooker($b, $by);
        $phone = Phone::normalize($phone) ?? throw new BookingException('bad_phone');
        $user = User::where('phone', $phone)->whereNotNull('phone_verified_at')->first();
        if ($user) {
            $this->add($b, $user, $by);

            return ['added', $user];
        }
        $this->ensureRoom($b);
        BookingPlayer::firstOrCreate(['booking_id' => $b->id, 'phone' => $phone], ['invited_by' => $by->id, 'status' => 'invited']);

        return ['invited', null];
    }

    /** The invite link of the booking (made on first use). */
    public function link(Booking $b): string
    {
        if (! $b->invite_token) {
            $b->forceFill(['invite_token' => Str::random(20)])->save();
        }

        return rtrim(config('app.url'), '/').'/join/'.$b->invite_token;
    }

    /** Someone opened the invite link while logged in. */
    public function join(Booking $b, User $user): void
    {
        if ($b->user_id === $user->id) {
            return;   // the booker
        }
        if (! $b->status->holdsTime() || $b->ends_at->isPast()) {
            throw new BookingException('not_active');
        }
        $this->add($b, $user, null, notifyBooker: true);
    }

    public function leave(Booking $b, User $user): void
    {
        BookingPlayer::where('booking_id', $b->id)->where('user_id', $user->id)->update(['status' => 'left']);
    }

    /** The booker removes a member or a waiting phone invite. */
    public function remove(Booking $b, User $by, BookingPlayer $player): void
    {
        $this->ensureBooker($b, $by);
        abort_unless($player->booking_id === $b->id, 404);
        $player->delete();
    }

    /** A phone was just confirmed: join the bookings that invited this number. */
    public function claimInvites(User $user): void
    {
        if (! $user->phone) {
            return;
        }
        $invites = BookingPlayer::with('booking')->where('phone', $user->phone)->whereNull('user_id')->where('status', 'invited')->get();
        foreach ($invites as $invite) {
            $b = $invite->booking;
            if (! $b || ! $b->status->holdsTime() || $b->ends_at->isPast() || $b->user_id === $user->id) {
                continue;
            }
            if (BookingPlayer::where('booking_id', $b->id)->where('user_id', $user->id)->exists()) {
                $invite->delete();

                continue;
            }
            $invite->update(['user_id' => $user->id, 'status' => 'joined']);
            $this->tellBooker($b, $user);
        }
    }

    private function add(Booking $b, User $user, ?User $by, bool $notifyBooker = false): bool
    {
        if ($b->user_id === $user->id) {
            return false;
        }
        // the same person: already a member, or a waiting invite to their phone
        $row = BookingPlayer::where('booking_id', $b->id)
            ->where(fn ($q) => $q->where('user_id', $user->id)->when($user->phone, fn ($q) => $q->orWhere(fn ($q) => $q->whereNull('user_id')->where('phone', $user->phone))))
            ->first();
        if ($row?->status === 'joined' && $row->user_id === $user->id) {
            return false;
        }
        if (! $row) {
            $this->ensureRoom($b);
        }
        $row ? $row->update(['user_id' => $user->id, 'status' => 'joined', 'invited_by' => $row->invited_by ?? $by?->id])
            : BookingPlayer::create(['booking_id' => $b->id, 'user_id' => $user->id, 'invited_by' => $by?->id, 'status' => 'joined']);

        if ($by) {
            $user->notify(new Notice('booking_team_added', ['by' => $by->name] + BookingPeople::params($b), '/me/bookings'));
        }
        if ($notifyBooker) {
            $this->tellBooker($b, $user);
        }

        return true;
    }

    private function tellBooker(Booking $b, User $joined): void
    {
        $b->loadMissing('user');
        $b->user?->notify(new Notice('booking_team_joined', ['who' => $joined->name] + BookingPeople::params($b), '/me/bookings'));
    }

    private function ensureBooker(Booking $b, User $by): void
    {
        if ($b->user_id !== $by->id) {
            throw new BookingException('not_yours');
        }
        if (! $b->status->holdsTime() || $b->ends_at->isPast()) {
            throw new BookingException('not_active');
        }
    }

    private function ensureRoom(Booking $b): void
    {
        $b->loadMissing('field');
        $max = max(2, (int) ($b->field->max_players ?: 22)) - 1;   // the booker is one of them
        if (BookingPlayer::where('booking_id', $b->id)->where('status', '!=', 'left')->count() >= $max) {
            throw new BookingException('team_full');
        }
    }
}
