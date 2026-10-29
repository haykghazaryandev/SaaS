<?php

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\DecideBooking;
use App\Domain\Booking\Actions\ExpireAndComplete;
use App\Domain\Booking\Actions\RequestBooking;
use App\Domain\Booking\AfterBooking\Attendance;
use App\Domain\Booking\AfterBooking\BookingPeople;
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
use Illuminate\Support\Collection;
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
    // a week after next Wednesday; when that day is too near the 14 days one can book ahead: a week earlier (the day
    // decides, not the hour, so all the games of a test stay on the same day)
    $when = at(0, 7)->greaterThan(now()->addDays(12)) ? at($hour) : at($hour, 7);
    $b = app(RequestBooking::class)->handle($player ?? $test->player, $test->small, $when, 60);

    return app(DecideBooking::class)->accept($b, $test->owner)->fresh();
}

function notices(User $user, string $type): Collection
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
    app(ExpireAndComplete::class)->completeEnded();
    expect($b->fresh()->status)->toBe(BookingStatus::Completed);
    // the owner forgot during the game: still possible the next morning
    app(Attendance::class)->noShow($this->owner, $b->fresh());
    expect($b->fresh()->status)->toBe(BookingStatus::NoShow)
        ->and($this->player->fresh()->no_show_count)->toBe(1)
        ->and(notices($this->player, 'booking_no_show'))->toHaveCount(1);

    $other = acceptedBooking($this, null, 18);
    $this->travelTo(BookingPeople::noShowDeadline($other)->addMinute());
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
    app(ExpireAndComplete::class)->completeEnded();
    $r = app(Reminders::class);
    expect($r->askRatings())->toBe(1);   // only the arrived one
    Notification::assertSentTo($this->player, Notice::class, fn ($n) => $n->type === 'booking_rate' && $n->link === '/rate/'.$came->id);

    // not marked: asked once the no-show deadline has passed
    $this->travelTo(BookingPeople::noShowDeadline($unmarked)->addMinutes(5));
    expect($r->askRatings())->toBe(1)->and(Booking::find($missed->id)->rating_asked_at)->toBeNull();
});

it('updates the place rating from the stars', function () {
    $this->venue->forceFill(['rating_avg' => 4.0, 'rating_count' => 4])->save();
    $b = acceptedBooking($this);
    $this->travelTo($b->ends_at->copy()->addHour());
    app(ExpireAndComplete::class)->completeEnded();

    app(Reviews::class)->rate($b->fresh(), $this->player, 5);
    expect((float) $this->venue->fresh()->rating_avg)->toBe(4.2)->and($this->venue->fresh()->rating_count)->toBe(5);
    app(Reviews::class)->rate($b->fresh(), $this->player, 3);   // changed their mind: same count
    expect((float) $this->venue->fresh()->rating_avg)->toBe(3.8)->and(VenueReview::count())->toBe(1);
    expect(fn () => app(Reviews::class)->rate($b->fresh(), User::factory()->create(), 5))->toThrow(BookingException::class);
});

// ---------- the booking's team ----------

it('invites friends: they join only when they accept, then hear about changes', function () {
    $b = acceptedBooking($this);
    $friend = User::factory()->create(['telegram_chat_id' => 5002]);
    $stranger = User::factory()->create();
    Friendship::create(['user_id' => $this->player->id, 'friend_id' => $friend->id, 'status' => 'accepted', 'accepted_at' => now()]);

    expect(app(BookingTeam::class)->inviteFriends($b, $this->player, [$friend->id, $stranger->id]))->toBe(1)
        ->and(notices($friend, 'booking_team_invite'))->toHaveCount(1)
        ->and($b->fresh()->attendees()->pluck('id'))->not->toContain($friend->id)   // no reminders before answering
        ->and(app(BookingTeam::class)->inviteFriends($b, $this->player, [$friend->id]))->toBe(0)   // asked already
        ->and(fn () => app(BookingTeam::class)->inviteFriends($b, $friend, [$stranger->id]))->toThrow(BookingException::class);

    // the friend sees the invite in My bookings and accepts there
    $this->actingAs($friend)->get('/me/bookings')->assertInertia(fn ($p) => $p->where('upcoming.0.id', $b->id)->where('upcoming.0.role', 'invited'));
    $this->actingAs($friend)->post("/bookings/{$b->id}/team/respond", ['accept' => true])->assertSessionHasNoErrors();
    expect($b->fresh()->attendees()->pluck('id'))->toContain($friend->id)
        ->and(notices($this->player, 'booking_team_joined'))->toHaveCount(1);

    app(CancelBooking::class)->handle($b, $this->player, 'player');
    expect(notices($friend, 'booking_team_cancelled'))->toHaveCount(1);
});

it('answers an invite with the Telegram buttons, and only the invited person can', function () {
    $b = acceptedBooking($this);
    [$yes, $no] = [User::factory()->create(['telegram_chat_id' => 5101]), User::factory()->create(['telegram_chat_id' => 5102])];
    foreach ([$yes, $no] as $f) {
        Friendship::create(['user_id' => $this->player->id, 'friend_id' => $f->id, 'status' => 'accepted', 'accepted_at' => now()]);
    }
    app(BookingTeam::class)->inviteFriends($b, $this->player, [$yes->id, $no->id]);
    $rowYes = BookingPlayer::where('user_id', $yes->id)->first();
    $rowNo = BookingPlayer::where('user_id', $no->id)->first();

    tapButton($this, 5102, "teamyes:{$rowYes->id}");   // someone else's invite: refused
    expect($rowYes->fresh()->status)->toBe('invited');
    tapButton($this, 5101, "teamyes:{$rowYes->id}");
    tapButton($this, 5102, "teamno:{$rowNo->id}");
    expect($rowYes->fresh()->status)->toBe('joined')->and($rowNo->fresh()->status)->toBe('declined')
        ->and(notices($this->player, 'booking_team_declined'))->toHaveCount(1);
});

it('joins with the invite link without an answer, also right after logging in', function () {
    $b = acceptedBooking($this);
    $path = parse_url(app(BookingTeam::class)->link($b), PHP_URL_PATH);
    $mate = User::factory()->create();

    // logged out: log in first, then back to the link
    $this->get($path)->assertRedirect(route('login'));
    expect(session('url.intended'))->toEndWith($path);

    $this->actingAs($mate)->get($path)->assertRedirect('/me/bookings');
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
    app(ExpireAndComplete::class)->completeEnded();
    tapButton($this, 5001, "rate:{$played->id}:4");
    expect(VenueReview::where('booking_id', $played->id)->value('stars'))->toBe(4);
});
