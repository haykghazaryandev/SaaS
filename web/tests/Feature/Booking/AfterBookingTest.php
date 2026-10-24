<?php

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\DecideBooking;
use App\Domain\Booking\Actions\RequestBooking;
use App\Domain\Booking\AfterBooking\Attendance;
use App\Domain\Booking\AfterBooking\BookingTeam;
use App\Domain\Booking\AfterBooking\Penalties;
use App\Domain\Booking\AfterBooking\Reminders;
use App\Domain\Booking\AfterBooking\Reviews;
use App\Domain\Booking\BookingException;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingPlayer;
use App\Models\Friendship;
use App\Models\User;
use App\Models\VenueReview;
use App\Notifications\Notice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['services.telegram.bot_token' => 'test-token', 'services.telegram.webhook_secret' => 'hook-secret']);
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    $this->venue = makeVenue();
    $this->small = $this->venue->fields->firstWhere('size.value', 'small');
    $this->owner = User::factory()->create(['telegram_chat_id' => 7001, 'name' => 'Gor Owner']);
    $this->venue->staff()->attach($this->owner->id, ['role' => 'owner']);
    $this->player = User::factory()->create(['telegram_chat_id' => 5001, 'name' => 'Aram']);
});

/** An accepted booking 8 days ahead at 21:00 (made now, so all reminders apply). */
function acceptedBooking($test, ?User $player = null, float $hour = 21): Booking
{
    $b = app(RequestBooking::class)->handle($player ?? $test->player, $test->small, at($hour, 7), 60);

    return app(DecideBooking::class)->accept($b, $test->owner)->fresh();
}

function notices(User $user, string $type): Illuminate\Support\Collection
{
    return $user->fresh()->notifications()->get()->filter(fn ($n) => $n->data['type'] === $type)->values();
}

// ---------- at the field ----------

it('lets the place mark arrival from 30 minutes before, and the player say "late"', function () {
    $b = acceptedBooking($this);
    expect(fn () => app(Attendance::class)->arrived($this->owner, $b))->toThrow(BookingException::class);

    $this->travelTo($b->starts_at->copy()->subMinutes(40));
    app(Attendance::class)->late($this->player, $b, 10);
    expect($b->fresh()->late_minutes)->toBe(10)->and(notices($this->owner, 'booking_late'))->toHaveCount(1);

    $this->travelTo($b->starts_at->copy()->subMinutes(20));
    app(Attendance::class)->arrived($this->owner, $b);
    expect($b->fresh()->arrived_at)->not->toBeNull()
        // after "arrived", no "late" and no no-show
        ->and(fn () => app(Attendance::class)->late($this->player, $b->fresh(), 5))->toThrow(BookingException::class);
    $this->travelTo($b->starts_at->copy()->addMinutes(20));
    expect(fn () => app(Attendance::class)->noShow($this->owner, $b->fresh()))->toThrow(BookingException::class);
});

it('marks a no-show until 12:00 the next day, tells the player and frees the rest of the time', function () {
    $b = acceptedBooking($this);
    expect(fn () => app(Attendance::class)->noShow($this->owner, $b))->toThrow(BookingException::class);   // not started

    $this->travelTo($b->ends_at->copy()->addHours(2));
    app(\App\Domain\Booking\Actions\ExpireAndComplete::class)->completeEnded();
    expect($b->fresh()->status)->toBe(BookingStatus::Completed);
    // the owner forgot during the game: still possible the next morning
    app(Attendance::class)->noShow($this->owner, $b->fresh());
    expect($b->fresh()->status)->toBe(BookingStatus::NoShow)
        ->and($this->player->fresh()->no_show_count)->toBe(1)
        ->and(notices($this->player, 'booking_no_show'))->toHaveCount(1);

    $other = acceptedBooking($this, null, 18);
    $this->travelTo(\App\Domain\Booking\AfterBooking\BookingPeople::noShowDeadline($other)->addMinute());
    expect(fn () => app(Attendance::class)->noShow($this->owner, $other->fresh()))->toThrow(BookingException::class);
});

it('pauses booking after 3 points in 60 days (a very late cancel is half), and the admin can reset it', function () {
    $penalties = app(Penalties::class);
    foreach ([12, 14] as $h) {
        $b = acceptedBooking($this, null, $h);
        $this->travelTo($b->starts_at->copy()->addMinutes(30));
        app(Attendance::class)->noShow($this->owner, $b);
        $this->travelBack();
    }
    expect($penalties->points($this->player->fresh()))->toBe(2.0);

    foreach ([16, 18] as $h) {
        $b = acceptedBooking($this, null, $h);
        $this->travelTo($b->starts_at->copy()->subMinutes(90));   // less than 2 h before
        app(CancelBooking::class)->handle($b, $this->player, 'player');
        $this->travelBack();
    }
    $p = $this->player->fresh();
    expect($penalties->points($p))->toBe(3.0)->and($p->isBookingBanned())->toBeTrue()
        ->and(notices($p, 'booking_ban'))->toHaveCount(1)
        ->and(fn () => app(RequestBooking::class)->handle($p, $this->small, at(10, 9), 60))->toThrow(BookingException::class, __('booking.error.banned'));

    $this->travelTo(at(23, 8));   // the admin resets it later
    $penalties->reset($p);
    expect($p->fresh()->isBookingBanned())->toBeFalse()->and($penalties->points($p->fresh()))->toBe(0.0);
});

// ---------- reminders ----------

it('reminds 24 h and 1 h before, once, to the booker and the team; the place is asked "not here?" after the grace time', function () {
    Notification::fake();
    $b = acceptedBooking($this);
    $mate = User::factory()->create();
    BookingPlayer::create(['booking_id' => $b->id, 'user_id' => $mate->id, 'status' => 'joined']);
    $r = app(Reminders::class);

    $this->travelTo($b->starts_at->copy()->subHours(23));
    expect($r->dayBefore())->toBe(1)->and($r->dayBefore())->toBe(0);
    Notification::assertSentTo([$this->player, $mate], Notice::class, fn ($n) => $n->type === 'booking_reminder_day');

    $this->travelTo($b->starts_at->copy()->subMinutes(55));
    expect($r->hourBefore())->toBe(1);
    // the booker can say "late" or "can't come"; the team only "late"
    Notification::assertSentTo($this->player, Notice::class, fn ($n) => $n->type === 'booking_reminder_hour' && str_starts_with($n->buttons[1][0][1], 'cantcome:'));
    Notification::assertSentTo($mate, Notice::class, fn ($n) => $n->type === 'booking_reminder_hour' && count($n->buttons) === 1);

    $this->travelTo($b->starts_at->copy()->addMinutes(10));
    expect($r->notHere())->toBe(0);   // inside the 15 min grace time
    $this->travelTo($b->starts_at->copy()->addMinutes(16));
    expect($r->notHere())->toBe(1)->and($r->notHere())->toBe(0);
    Notification::assertSentTo($this->owner, Notice::class, fn ($n) => $n->type === 'booking_not_here' && $n->buttons[0][1][1] === "noshow:{$b->id}");
});

it('skips the 24 h reminder for a booking made less than a day ahead', function () {
    Notification::fake();
    $b = acceptedBooking($this);
    $b->forceFill(['created_at' => $b->starts_at->copy()->subHours(20)])->save();
    $this->travelTo($b->starts_at->copy()->subHours(19));
    expect(app(Reminders::class)->dayBefore())->toBe(0);
});

// ---------- how was the field ----------

it('asks "how was the field?" 30 min after a game the place marked as arrived, never after a no-show', function () {
    Notification::fake();
    $came = acceptedBooking($this, null, 18);
    $missed = acceptedBooking($this, null, 20);
    $unmarked = acceptedBooking($this, null, 22);
    $this->travelTo($came->starts_at->copy()->addMinutes(5));
    app(Attendance::class)->arrived($this->owner, $came);
    $this->travelTo($missed->starts_at->copy()->addMinutes(20));
    app(Attendance::class)->noShow($this->owner, $missed);

    $this->travelTo($unmarked->ends_at->copy()->addMinutes(40));
    app(\App\Domain\Booking\Actions\ExpireAndComplete::class)->completeEnded();
    $r = app(Reminders::class);
    expect($r->askRatings())->toBe(1);   // only the arrived one
    Notification::assertSentTo($this->player, Notice::class, fn ($n) => $n->type === 'booking_rate' && $n->link === '/rate/'.$came->id);

    // not marked: asked once the no-show deadline has passed
    $this->travelTo(\App\Domain\Booking\AfterBooking\BookingPeople::noShowDeadline($unmarked)->addMinutes(5));
    expect($r->askRatings())->toBe(1)->and(Booking::find($missed->id)->rating_asked_at)->toBeNull();
});

it('updates the place rating from the stars', function () {
    $this->venue->forceFill(['rating_avg' => 4.0, 'rating_count' => 4])->save();
    $b = acceptedBooking($this);
    $this->travelTo($b->ends_at->copy()->addHour());
    app(\App\Domain\Booking\Actions\ExpireAndComplete::class)->completeEnded();

    app(Reviews::class)->rate($b->fresh(), $this->player, 5);
    expect((float) $this->venue->fresh()->rating_avg)->toBe(4.2)->and($this->venue->fresh()->rating_count)->toBe(5);
    app(Reviews::class)->rate($b->fresh(), $this->player, 3);   // changed their mind: same count
    expect((float) $this->venue->fresh()->rating_avg)->toBe(3.8)->and(VenueReview::count())->toBe(1);
    expect(fn () => app(Reviews::class)->rate($b->fresh(), User::factory()->create(), 5))->toThrow(BookingException::class);
});

// ---------- the booking's team ----------

it('adds friends to the booking and tells the team about changes', function () {
    $b = acceptedBooking($this);
    $friend = User::factory()->create(['telegram_chat_id' => 5002]);
    $stranger = User::factory()->create();
    Friendship::create(['user_id' => $this->player->id, 'friend_id' => $friend->id, 'status' => 'accepted', 'accepted_at' => now()]);

    expect(app(BookingTeam::class)->addFriends($b, $this->player, [$friend->id, $stranger->id]))->toBe(1)
        ->and(notices($friend, 'booking_team_added'))->toHaveCount(1)
        ->and(fn () => app(BookingTeam::class)->addFriends($b, $friend, [$stranger->id]))->toThrow(BookingException::class);

    app(CancelBooking::class)->handle($b, $this->player, 'player');
    expect(notices($friend, 'booking_team_cancelled'))->toHaveCount(1);
});

it('invites by phone: an account is added at once, a new number joins when it logs in with Telegram', function () {
    $b = acceptedBooking($this);
    $known = User::factory()->create(['phone' => '+37491555111', 'phone_verified_at' => now()]);
    expect(app(BookingTeam::class)->invitePhone($b, $this->player, '091 555 111')[0])->toBe('added')
        ->and(app(BookingTeam::class)->invitePhone($b, $this->player, '093 777 888')[0])->toBe('invited');

    // that person logs in with Telegram for the first time
    $token = $this->postJson('/login/telegram')->json('token');
    $from = ['id' => 999001, 'first_name' => 'Vahe'];
    foreach ([['text' => "/start login_{$token}"], ['contact' => ['user_id' => 999001, 'phone_number' => '37493777888', 'first_name' => 'Vahe']]] as $i => $m) {
        $this->postJson('/telegram/webhook/hook-secret', ['update_id' => $i, 'message' => ['message_id' => $i, 'chat' => ['id' => 999001, 'type' => 'private'], 'from' => $from] + $m], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret']);
    }
    $vahe = User::where('phone', '+37493777888')->first();
    expect($b->fresh()->attendees()->pluck('id')->all())->toContain($known->id, $vahe->id)
        ->and(notices($this->player, 'booking_team_joined'))->toHaveCount(1);
});

it('lets anyone with the invite link join, and members leave', function () {
    $b = acceptedBooking($this);
    $link = app(BookingTeam::class)->link($b);
    $mate = User::factory()->create();

    $this->actingAs($mate)->get(parse_url($link, PHP_URL_PATH))->assertRedirect('/me/bookings');
    expect($b->fresh()->attendees()->pluck('id'))->toContain($mate->id);
    $this->actingAs($mate)->get('/me/bookings')->assertInertia(fn ($p) => $p->where('upcoming.0.id', $b->id)->where('upcoming.0.role', 'member'));

    $this->actingAs($mate)->post("/bookings/{$b->id}/leave")->assertRedirect();
    expect($b->fresh()->attendees()->pluck('id'))->not->toContain($mate->id);
});

// ---------- Telegram buttons ----------

function tapButton($test, int $chatId, string $data): void
{
    $test->postJson('/telegram/webhook/hook-secret', ['update_id' => random_int(1, 99999), 'callback_query' => [
        'id' => 'q'.random_int(1, 99999), 'from' => ['id' => $chatId], 'data' => $data, 'message' => ['message_id' => 9, 'chat' => ['id' => $chatId]],
    ]], ['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])->assertOk();
}

it('handles the bot buttons: late by the player, no-show by the place, nothing for others', function () {
    $b = acceptedBooking($this);
    $this->travelTo($b->starts_at->copy()->subMinutes(50));
    tapButton($this, 5001, "late:{$b->id}:10");
    expect($b->fresh()->late_minutes)->toBe(10);

    $intruder = User::factory()->create(['telegram_chat_id' => 6666]);
    tapButton($this, 6666, "noshow:{$b->id}");
    tapButton($this, 5001, "noshow:{$b->id}");   // the player can't mark themselves
    $this->travelTo($b->starts_at->copy()->addMinutes(20));
    expect($b->fresh()->status)->toBe(BookingStatus::Accepted);

    tapButton($this, 7001, "noshow:{$b->id}");
    expect($b->fresh()->status)->toBe(BookingStatus::NoShow);
});

it('cancels from the reminder after a confirmation, and rates with the stars', function () {
    $b = acceptedBooking($this);
    $this->travelTo($b->starts_at->copy()->subMinutes(50));
    tapButton($this, 5001, "cantcome:{$b->id}");
    expect($b->fresh()->status)->toBe(BookingStatus::Accepted);   // asks first
    tapButton($this, 5001, "cancelyes:{$b->id}");
    expect($b->fresh()->status)->toBe(BookingStatus::Cancelled);

    $this->travelBack();
    $played = acceptedBooking($this, null, 18);
    $this->travelTo($played->ends_at->copy()->addHour());
    app(\App\Domain\Booking\Actions\ExpireAndComplete::class)->completeEnded();
    tapButton($this, 5001, "rate:{$played->id}:4");
    expect(VenueReview::where('booking_id', $played->id)->value('stars'))->toBe(4);
});
