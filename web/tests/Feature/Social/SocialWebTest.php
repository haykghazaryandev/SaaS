<?php

use App\Domain\Chat\Chat;
use App\Domain\Social\Friends;
use App\Models\Friendship;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Report;
use App\Models\User;
use App\Models\UserBlock;
use App\Notifications\Notice;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->f = app(Friends::class);
    $this->me = User::factory()->create(['name' => 'Aram Petrosyan', 'username' => 'aram']);
    $this->other = User::factory()->create(['name' => 'Davit Sargsyan', 'username' => 'davo', 'phone' => '+37493555111']);
});

function makeFriends(User $a, User $b): void
{
    Friendship::create(['user_id' => $a->id, 'friend_id' => $b->id, 'status' => 'accepted', 'accepted_at' => now()]);
}

/** A game with these users in it (accepted / joined). */
function gameWith(array $users, string $status = 'open'): Game
{
    $g = Game::create(['creator_id' => $users[0]->id, 'players_per_side' => 5, 'total_players' => 10, 'min_to_open' => 3, 'status' => $status]);
    foreach ($users as $i => $u) {
        GamePlayer::create(['game_id' => $g->id, 'user_id' => $u->id, 'status' => $i ? 'joined' : 'accepted']);
    }

    return $g;
}

// ---------- auth ----------

it('sends guests to the login page', function (string $method, string $url) {
    $this->{$method}($url)->assertRedirect(route('login'));
})->with([
    ['get', '/friends'], ['get', '/me/profile'], ['get', '/u/1'], ['post', '/friends/1'], ['post', '/friends/1/accept'],
    ['delete', '/friends/1'], ['post', '/users/1/block'], ['delete', '/users/1/block'], ['post', '/reports'], ['post', '/notifications/read'],
]);

it('answers 401 to guests asking for notifications as JSON', function () {
    $this->getJson('/notifications')->assertUnauthorized();
});

// ---------- friends page ----------

it('shows friends, received and sent requests', function () {
    makeFriends($this->me, $this->other);
    $asker = User::factory()->create();
    $asked = User::factory()->create();
    Friendship::create(['user_id' => $asker->id, 'friend_id' => $this->me->id, 'status' => 'pending']);
    Friendship::create(['user_id' => $this->me->id, 'friend_id' => $asked->id, 'status' => 'pending']);

    $this->actingAs($this->me)->get('/friends')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('friends/Index')
        ->where('tab', 'friends')
        ->has('friends', 1)->where('friends.0.id', $this->other->id)->where('friends.0.relation', 'friends')->where('friends.0.url', route('users.show', 'davo'))
        ->has('received', 1)->where('received.0.id', $asker->id)->where('received.0.relation', 'received')
        ->has('sent', 1)->where('sent.0.id', $asked->id)->where('sent.0.relation', 'sent')
        ->where('counts.friendRequests', 1)
    );
});

it('sends, accepts, declines, cancels and removes friend requests', function () {
    $this->actingAs($this->me)->post("/friends/{$this->other->id}")->assertRedirect()->assertSessionHas('success', __('friends.flash.requested'));
    expect($this->f->relation($this->me, $this->other))->toBe('sent')
        ->and($this->other->notifications()->count())->toBe(1);

    // cancel my own request
    $this->delete("/friends/{$this->other->id}")->assertSessionHas('success', __('friends.flash.cancelled'));
    expect(Friendship::between($this->me->id, $this->other->id))->toBeNull();

    // the other one asks, I decline
    $this->f->request($this->other, $this->me);
    $this->delete("/friends/{$this->other->id}")->assertSessionHas('success', __('friends.flash.declined'));
    expect(Friendship::between($this->me->id, $this->other->id))->toBeNull();

    // asks again, I accept
    $this->f->request($this->other, $this->me);
    $this->post("/friends/{$this->other->id}/accept")->assertSessionHas('success', __('friends.flash.accepted'));
    expect($this->me->isFriendOf($this->other))->toBeTrue();

    // remove
    $this->delete("/friends/{$this->other->id}")->assertSessionHas('success', __('friends.flash.removed'));
    expect($this->me->isFriendOf($this->other))->toBeFalse();
});

it('makes friends at once when the other already asked', function () {
    $this->f->request($this->other, $this->me);
    $this->actingAs($this->me)->post("/friends/{$this->other->id}")->assertSessionHas('success', __('friends.flash.accepted'));
    expect($this->me->isFriendOf($this->other))->toBeTrue();
});

it('refuses to accept a request that was not sent to me', function () {
    $this->f->request($this->me, $this->other);   // I asked: I can't accept it myself
    $this->actingAs($this->me)->post("/friends/{$this->other->id}/accept")->assertSessionHas('error', __('social.error.not_found'));
    $this->actingAs($this->me)->post('/friends/'.User::factory()->create()->id.'/accept')->assertSessionHas('error', __('social.error.not_found'));
    expect($this->f->relation($this->me, $this->other))->toBe('sent');
});

it('refuses friend requests to myself and to 404 users', function () {
    $this->actingAs($this->me)->post("/friends/{$this->me->id}")->assertSessionHas('error', __('social.error.self'));
    $this->post('/friends/999999')->assertNotFound();
});

// ---------- search / played with ----------

it('finds players by name, @username and phone, and needs 2 characters', function () {
    $this->actingAs($this->me);
    $find = fn (string $q) => $this->get('/friends?tab=find&q='.urlencode($q))->assertOk()->viewData('page')['props']['results'];

    expect($find('d'))->toBe([])
        ->and(collect($find('davit'))->pluck('id')->all())->toBe([$this->other->id])
        ->and(collect($find('SARGS'))->pluck('id')->all())->toBe([$this->other->id])
        ->and(collect($find('@dav'))->pluck('id')->all())->toBe([$this->other->id])
        ->and(collect($find('093 555 111'))->pluck('id')->all())->toBe([$this->other->id])
        ->and(collect($find('+374 93 555 112'))->all())->toBe([])
        ->and(collect($find('aram'))->all())->toBe([]);    // not myself
});

it('leaves blocked players and unfinished accounts out of the search', function () {
    $blocker = User::factory()->create(['name' => 'Davit Blocker']);
    $guest = User::factory()->guest()->create(['name' => 'Davit Guest']);
    UserBlock::create(['blocker_id' => $this->me->id, 'blocked_id' => $this->other->id]);
    UserBlock::create(['blocker_id' => $blocker->id, 'blocked_id' => $this->me->id]);
    $ok = User::factory()->create(['name' => 'Davit Ok']);

    $this->actingAs($this->me)->get('/friends?tab=find&q=davit')->assertInertia(fn (Assert $p) => $p
        ->where('tab', 'find')->where('q', 'davit')->has('results', 1)->where('results.0.id', $ok->id)->where('results.0.relation', 'none')
    );
});

it('lists players I played with who are not my friends yet', function () {
    $friend = User::factory()->create();
    $stranger = User::factory()->create();
    $blocked = User::factory()->create();
    $cancelledMate = User::factory()->create();
    makeFriends($this->me, $friend);
    UserBlock::create(['blocker_id' => $blocked->id, 'blocked_id' => $this->me->id]);
    gameWith([$this->me, $this->other, $friend, $blocked], 'played');
    gameWith([$this->other, $this->me]);
    gameWith([$this->me, $stranger]);
    gameWith([$this->me, $cancelledMate], 'cancelled');
    gameWith([$this->other, $stranger]);   // a game without me

    $this->actingAs($this->me)->get('/friends?tab=played')->assertInertia(fn (Assert $p) => $p
        ->where('tab', 'played')
        ->has('played', 2)
        ->where('played.0.id', $this->other->id)->where('played.0.games', 2)
        ->where('played.1.id', $stranger->id)->where('played.1.games', 1)
    );
});

// ---------- profile ----------

it('opens a profile by username or by id', function () {
    makeFriends($this->me, $this->other);
    gameWith([$this->me, $this->other], 'full');
    gameWith([$this->other, $this->me], 'forming');   // forming games don't count
    $this->other->update(['position' => 'forward', 'district' => 'arabkir', 'bio' => 'Left foot']);

    $this->actingAs($this->me)->get('/u/davo')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('profile/Show')
        ->where('player.id', $this->other->id)->where('player.username', 'davo')
        ->where('player.position', 'forward')->where('player.bio', 'Left foot')
        ->where('player.games', 1)->where('player.friends', 1)->where('player.relation', 'friends')
        ->where('hidden', false)->where('canMessage', true)
    );
    $noName = User::factory()->create(['username' => null]);
    $this->get("/u/{$noName->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('player.id', $noName->id)->where('player.relation', 'none')->where('canMessage', false));
    $this->get('/u/DAVO')->assertOk();
    $this->get('/u/nobody_here')->assertNotFound();
    $this->get('/u/999999')->assertNotFound();
});

it('lets players of the same game message each other', function () {
    gameWith([$this->me, $this->other]);
    $this->actingAs($this->me)->get('/u/davo')->assertInertia(fn (Assert $p) => $p->where('player.relation', 'none')->where('canMessage', true));
});

it('shows my own profile as self', function () {
    $this->actingAs($this->me)->get('/u/aram')->assertInertia(fn (Assert $p) => $p->where('player.relation', 'self')->where('canMessage', false));
});

// ---------- block ----------

it('blocks: ends the friendship, hides the profile, stops requests and messages; unblock undoes it', function () {
    makeFriends($this->me, $this->other);
    $this->actingAs($this->me)->post("/users/{$this->other->id}/block")->assertSessionHas('success');
    expect(UserBlock::between($this->me->id, $this->other->id))->toBeTrue()
        ->and($this->me->isFriendOf($this->other))->toBeFalse()
        ->and(app(Chat::class)->canMessage($this->me, $this->other))->toBeFalse();

    // I see that I blocked them, without details
    $this->get('/u/davo')->assertInertia(fn (Assert $p) => $p->where('hidden', true)->where('blockedByMe', true)
        ->where('player.name', 'Davit Sargsyan')->missing('player.bio')->missing('player.games')->where('canMessage', false));
    // they see nothing
    $this->actingAs($this->other)->get('/u/aram')->assertInertia(fn (Assert $p) => $p->where('hidden', true)->where('blockedByMe', false)->where('player.name', '—'));
    // they can't ask to be friends
    $this->post("/friends/{$this->me->id}")->assertSessionHas('error', __('social.error.blocked'));
    expect(Friendship::between($this->me->id, $this->other->id))->toBeNull();

    // my profile lists them; unblock
    $this->actingAs($this->me)->get('/me/profile')->assertInertia(fn (Assert $p) => $p->has('blocked', 1)->where('blocked.0.id', $this->other->id));
    $this->delete("/users/{$this->other->id}/block")->assertSessionHas('success');
    expect(UserBlock::between($this->me->id, $this->other->id))->toBeFalse();
    $this->actingAs($this->me)->get('/me/profile')->assertInertia(fn (Assert $p) => $p->has('blocked', 0));
});

it('does not let the blocked person lift the block', function () {
    UserBlock::create(['blocker_id' => $this->me->id, 'blocked_id' => $this->other->id]);
    $this->actingAs($this->other)->delete("/users/{$this->me->id}/block");
    expect(UserBlock::between($this->me->id, $this->other->id))->toBeTrue();
});

it('refuses to block myself', function () {
    $this->actingAs($this->me)->post("/users/{$this->me->id}/block")->assertSessionHas('error', __('social.error.self'));
    expect(UserBlock::count())->toBe(0);
});

// ---------- reports ----------

it('saves reports about a user, a game or a message, and validates them', function () {
    $this->actingAs($this->me);
    $this->post('/reports', ['target_type' => 'user', 'target_id' => $this->other->id, 'reason' => 'Rude in the chat'])->assertSessionHas('success', __('friends.flash.reported'));
    $g = gameWith([$this->other]);
    $this->postJson('/reports', ['type' => 'game', 'id' => $g->id, 'target_type' => 'game', 'target_id' => $g->id, 'reason' => 'Spam game'])->assertOk()->assertJson(['ok' => true]);
    expect(Report::where('reporter_id', $this->me->id)->pluck('target_type')->all())->toBe(['user', 'game']);

    $this->postJson('/reports', ['target_type' => 'venue', 'target_id' => 1, 'reason' => 'xxx'])->assertJsonValidationErrors('target_type');
    $this->postJson('/reports', ['target_type' => 'user', 'target_id' => 999999, 'reason' => 'xxxx'])->assertJsonValidationErrors('target_id');
    $this->postJson('/reports', ['target_type' => 'message', 'target_id' => 999999, 'reason' => 'xxxx'])->assertJsonValidationErrors('target_id');
    $this->postJson('/reports', ['target_type' => 'user', 'target_id' => $this->other->id, 'reason' => ''])->assertJsonValidationErrors('reason');
    $this->postJson('/reports', ['target_type' => 'user', 'target_id' => $this->other->id, 'reason' => str_repeat('a', 501)])->assertJsonValidationErrors('reason');
    expect(Report::count())->toBe(2);
});

// ---------- my profile ----------

it('shows and updates my profile', function () {
    $this->actingAs($this->me)->get('/me/profile')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('profile/Edit')->where('profile.username', 'aram')->where('options.positions', ['goalkeeper', 'defender', 'midfielder', 'forward'])
        ->where('options.districts', fn ($d) => collect($d)->contains('arabkir'))
    );
    $this->patch('/me/profile', ['name' => '  Aram P ', 'username' => 'Aram_10', 'position' => 'goalkeeper', 'district' => 'kentron', 'bio' => 'Hi', 'locale' => 'ru'])
        ->assertRedirect()->assertSessionHas('success')->assertSessionHas('locale', 'ru');
    expect($this->me->fresh()->only(['name', 'username', 'position', 'district', 'bio', 'locale']))
        ->toBe(['name' => 'Aram P', 'username' => 'aram_10', 'position' => 'goalkeeper', 'district' => 'kentron', 'bio' => 'Hi', 'locale' => 'ru']);

    // clear the optional ones
    $this->patch('/me/profile', ['name' => 'Aram P', 'username' => '', 'position' => null, 'district' => null, 'bio' => '', 'locale' => 'hy'])->assertSessionHasNoErrors();
    expect($this->me->fresh()->only(['username', 'position', 'district', 'bio']))->toBe(['username' => null, 'position' => null, 'district' => null, 'bio' => null]);
});

it('validates my profile', function (array $data, string $error) {
    $this->actingAs($this->me)->patch('/me/profile', [...['name' => 'Aram', 'locale' => 'en'], ...$data])->assertSessionHasErrors($error);
})->with([
    'no name' => [['name' => ''], 'name'],
    'short name' => [['name' => 'A'], 'name'],
    'username taken' => [['username' => 'DAVO'], 'username'],
    'username chars' => [['username' => 'ara m!'], 'username'],
    'username short' => [['username' => 'ab'], 'username'],
    'bad position' => [['position' => 'striker'], 'position'],
    'bad district' => [['district' => 'paris'], 'district'],
    'long bio' => [['bio' => str_repeat('a', 301)], 'bio'],
    'bad locale' => [['locale' => 'de'], 'locale'],
]);

// ---------- notifications ----------

it('lists notifications with texts in my language and marks them read', function () {
    $this->me->update(['locale' => 'en']);
    $this->me->notify(new Notice('friend_request', ['name' => 'Davit'], '/friends'));
    $this->me->notify(new Notice('game_invite', ['name' => 'Narek', 'format' => '5×5'], '/games/1'));

    $res = $this->actingAs($this->me)->getJson('/notifications')->assertOk()->assertJsonPath('counts.notifications', 2)->json();
    expect($res['items'])->toHaveCount(2)
        ->and(collect($res['items'])->pluck('text')->all())->toContain('Davit wants to be your friend', 'Narek invites you to a 5×5 game')
        ->and($res['items'][0]['read_at'])->toBeNull();

    // in Russian when the session language is Russian
    $ru = $this->withSession(['locale' => 'ru'])->getJson('/notifications')->json('items');
    expect(collect($ru)->pluck('text')->all())->toContain(__('notice.friend_request', ['name' => 'Davit'], 'ru'));

    // one
    $id = $res['items'][0]['id'];
    $this->postJson('/notifications/read', ['id' => $id])->assertOk()->assertJsonPath('counts.notifications', 1);
    expect($this->me->notifications()->find($id)->read_at)->not->toBeNull();
    // all
    $this->postJson('/notifications/read')->assertOk()->assertJsonPath('counts.notifications', 0);
    expect($this->me->unreadNotifications()->count())->toBe(0);
});

it('keeps only the last 30 notifications and never shows other people\'s', function () {
    foreach (range(1, 32) as $i) {
        $this->me->notify(new Notice('friend_accepted', ['name' => "P$i"], '/friends'));
    }
    $this->other->notify(new Notice('friend_request', ['name' => 'X'], '/friends'));
    $this->actingAs($this->me)->getJson('/notifications')->assertJsonCount(30, 'items');
    // marking someone else's notice does nothing
    $theirs = $this->other->notifications()->first()->id;
    $this->postJson('/notifications/read', ['id' => $theirs]);
    expect($this->other->unreadNotifications()->count())->toBe(1);
});

it('shares header counts: notifications, chat messages and friend requests', function () {
    $this->f->request($this->other, $this->me);   // 1 request + 1 notification
    makeFriends($this->me, $asker = User::factory()->create());
    $chat = app(Chat::class);
    $c = $chat->direct($asker, $this->me);
    $chat->send($c, $asker, 'Barev');
    $chat->send($c, $asker, 'Are you coming?');

    $this->actingAs($this->me)->get('/friends')->assertInertia(fn (Assert $p) => $p
        ->where('counts.notifications', 1)->where('counts.messages', 2)->where('counts.friendRequests', 1)
    );
});

it('shares no counts with guests', function () {
    $this->get('/')->assertInertia(fn (Assert $p) => $p->where('counts', null));
});
