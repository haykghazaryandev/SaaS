<?php

namespace App\Domain\Booking\AfterBooking;

use App\Domain\Booking\BookingException;
use App\Models\Booking;
use App\Models\BookingPlayer;
use App\Models\User;
use App\Notifications\Notice;
use Illuminate\Support\Str;

/**
 * The team of a booking: the people who get its news and reminders.
 * - the booker invites friends: they are "invited" until they accept (Telegram buttons or My bookings);
 * - the invite link /join/{token} needs no answer: opening it means joining (logged-out people log in or
 *   register first and land back in the booking);
 * Only "joined" members get the reminders. Up to the field's player count (10 for 5×5, 22 for 11×11).
 * Statuses: invited | joined | declined | left
 */
class BookingTeam
{
    /** @param int[] $userIds friends of the booker; returns how many were invited */
    public function inviteFriends(Booking $b, User $by, array $userIds): int
    {
        $this->ensureFeature($b);
        $this->ensureBooker($b, $by);
        $n = 0;
        foreach (User::whereIn('id', array_intersect($userIds, $by->friendIds()))->get() as $friend) {
            $row = BookingPlayer::where('booking_id', $b->id)->where('user_id', $friend->id)->first();
            if ($friend->id === $b->user_id || in_array($row?->status, ['invited', 'joined'], true)) {
                continue;   // asked already, or in the team
            }
            $this->ensureRoom($b);
            $row ? $row->update(['status' => 'invited', 'invited_by' => $by->id])
                : $row = BookingPlayer::create(['booking_id' => $b->id, 'user_id' => $friend->id, 'invited_by' => $by->id, 'status' => 'invited']);
            $friend->notify(new Notice('booking_team_invite', ['by' => $by->name] + BookingPeople::params($b), '/me/bookings', [
                [['telegram.button.team_yes', "teamyes:{$row->id}"], ['telegram.button.team_no', "teamno:{$row->id}"]],
            ]));
            $n++;
        }

        return $n;
    }

    /** The invited friend answers. */
    public function respond(BookingPlayer $row, User $user, bool $accept): void
    {
        $b = $row->booking;
        if ($row->user_id !== $user->id || $row->status !== 'invited') {
            throw new BookingException('not_active');
        }
        if ($accept && (! $b->status->holdsTime() || $b->ends_at->isPast())) {
            throw new BookingException('not_active');
        }
        $row->update(['status' => $accept ? 'joined' : 'declined']);
        $b->loadMissing('user');
        $b->user?->notify(new Notice($accept ? 'booking_team_joined' : 'booking_team_declined', ['who' => $user->name] + BookingPeople::params($b), '/me/bookings'));
    }

    /** The invite link of the booking (made on first use). */
    public function link(Booking $b): string
    {
        if (! $b->invite_token) {
            $b->forceFill(['invite_token' => Str::random(20)])->save();
        }

        // with the Mini App registered (BotFather /newapp), the link opens Bookball right inside Telegram
        $app = config('services.telegram.app_name');
        if ($app && config('services.telegram.bot_username')) {
            return 'https://t.me/'.config('services.telegram.bot_username').'/'.$app.'?startapp=join_'.$b->invite_token;
        }

        return rtrim(config('app.url'), '/').'/join/'.$b->invite_token;
    }

    /** Someone opened the invite link while logged in: they are in, no answer needed. */
    public function join(Booking $b, User $user): void
    {
        $this->ensureFeature($b);
        if ($b->user_id === $user->id) {
            return;   // the booker
        }
        if (! $b->status->holdsTime() || $b->ends_at->isPast()) {
            throw new BookingException('not_active');
        }
        $row = BookingPlayer::where('booking_id', $b->id)->where('user_id', $user->id)->first();
        if ($row?->status === 'joined') {
            return;
        }
        if ($row?->status !== 'invited') {
            $this->ensureRoom($b);
        }
        $row ? $row->update(['status' => 'joined']) : BookingPlayer::create(['booking_id' => $b->id, 'user_id' => $user->id, 'status' => 'joined']);
        $b->loadMissing('user');
        $b->user?->notify(new Notice('booking_team_joined', ['who' => $user->name] + BookingPeople::params($b), '/me/bookings'));
    }

    public function leave(Booking $b, User $user): void
    {
        BookingPlayer::where('booking_id', $b->id)->where('user_id', $user->id)->update(['status' => 'left']);
    }

    /** The booker removes a member or takes back an invite. */
    public function remove(Booking $b, User $by, BookingPlayer $player): void
    {
        $this->ensureBooker($b, $by);
        abort_unless($player->booking_id === $b->id, 404);
        $player->delete();
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
        if (BookingPlayer::where('booking_id', $b->id)->whereIn('status', ['invited', 'joined'])->count() >= $max) {
            throw new BookingException('team_full');
        }
    }

    /** The place has the booking team switched off (admin → place → features). */
    private function ensureFeature(Booking $b): void
    {
        if (! $b->loadMissing('venue')->venue->hasFeature('team')) {
            throw new BookingException('feature_off');
        }
    }
}
