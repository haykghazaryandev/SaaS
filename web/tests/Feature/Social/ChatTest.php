<?php

use App\Domain\Chat\Chat;
use App\Domain\Chat\Events\ChatUpdated;
use App\Domain\Chat\Events\MessageSent;
use App\Domain\Games\Games;
use App\Domain\Social\Friends;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

/** a and b are friends with a direct chat; c is a stranger */
beforeEach(function () {
    $this->chat = app(Chat::class);
    [$this->a, $this->b, $this->c] = User::factory()->count(3)->create();
    app(Friends::class)->accept($this->b, app(Friends::class)->request($this->a, $this->b));
    $this->dm = $this->chat->direct($this->a, $this->b);
});

function gameChat(array $users): Conversation
{
    $c = Conversation::create(['type' => 'game', 'last_message_at' => now()]);
    foreach ($users as $u) {
        app(Chat::class)->join($c, $u->id);
    }

    return $c;
}

it('needs a login', function () {
    $this->get('/messages')->assertRedirect(route('login'));
    $this->get(route('messages.show', $this->dm))->assertRedirect(route('login'));
});

it('lists my chats with the last message and unread counts, closed chats apart', function () {
    $this->chat->send($this->dm, $this->b, 'Hi!');
    $this->chat->send($this->dm, $this->b, 'Thursday?');
    $old = gameChat([$this->a, $this->b]);
    $this->chat->system($old, 'chat.sys.archived', ['days' => 30]);
    $old->update(['status' => 'archived']);
    gameChat([$this->b, $this->c]);   // not mine

    $this->actingAs($this->a)->get('/messages')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('messages/Index')
        ->has('chats', 1)
        ->where('chats.0.id', $this->dm->id)
        ->where('chats.0.type', 'direct')
        ->where('chats.0.other.id', $this->b->id)
        ->where('chats.0.unread', 2)
        ->where('chats.0.last.body', 'Thursday?')
        ->has('archived', 1)
        ->where('archived.0.last.body', 'chat.sys.archived')
        ->where('archived.0.last.meta.days', 30));
});

it('shows a chat only to its members', function () {
    $this->chat->send($this->dm, $this->b, 'Hi!');
    $this->actingAs($this->c)->get(route('messages.show', $this->dm))->assertForbidden();
    $this->actingAs($this->a)->get(route('messages.show', $this->dm))->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('messages/Show')
        ->where('chat.id', $this->dm->id)
        ->where('chat.members', 2)
        ->has('messages', 1)
        ->where('messages.0.body', 'Hi!')
        ->where('hasMore', false));

    // someone who left a game chat can't open it any more; members of a closed chat still can
    $g = gameChat([$this->a, $this->b]);
    $this->chat->leave($g, $this->b->id);
    $this->actingAs($this->b)->get(route('messages.show', $g))->assertForbidden();
    $g->update(['status' => 'archived']);
    $this->actingAs($this->a)->get(route('messages.show', $g))->assertOk()->assertInertia(fn (Assert $p) => $p->where('chat.archived', true));
});

it('sends messages (with a reply) and refuses outsiders, closed chats and long texts', function () {
    $first = $this->chat->send($this->dm, $this->b, 'Who brings the ball?');
    $this->actingAs($this->a)->postJson(route('messages.send', $this->dm), ['body' => '  Me!  ', 'reply_to' => $first->id])
        ->assertCreated()
        ->assertJsonPath('message.body', 'Me!')
        ->assertJsonPath('message.user.id', $this->a->id)
        ->assertJsonPath('message.reply.id', $first->id)
        ->assertJsonPath('message.reply.body', 'Who brings the ball?');
    expect(Message::where('conversation_id', $this->dm->id)->count())->toBe(2);

    $this->actingAs($this->c)->postJson(route('messages.send', $this->dm), ['body' => 'hey'])->assertStatus(422)->assertJsonPath('reason', 'not_member');
    $this->actingAs($this->a)->postJson(route('messages.send', $this->dm), ['body' => str_repeat('a', 2001)])->assertStatus(422);
    $this->actingAs($this->a)->postJson(route('messages.send', $this->dm), ['body' => '   '])->assertStatus(422);

    $this->dm->update(['status' => 'archived']);
    $this->actingAs($this->a)->postJson(route('messages.send', $this->dm), ['body' => 'late'])->assertStatus(422)->assertJsonPath('reason', 'chat_archived');
});

it('lets the author edit within 15 minutes only', function () {
    $m = $this->chat->send($this->dm, $this->a, 'See you at 20');
    $this->actingAs($this->b)->patchJson(route('messages.update', $m), ['body' => 'hacked'])->assertStatus(422);
    $this->actingAs($this->a)->patchJson(route('messages.update', $m), ['body' => 'See you at 21'])
        ->assertOk()->assertJsonPath('message.body', 'See you at 21')->assertJsonPath('message.edited', true);

    $this->travel(16)->minutes();
    $this->actingAs($this->a)->patchJson(route('messages.update', $m), ['body' => 'too late'])->assertStatus(422)->assertJsonPath('reason', 'cannot_edit');
    expect($m->fresh()->body)->toBe('See you at 21');
});

it('lets the author delete within 15 minutes; the chat shows it as deleted', function () {
    $m = $this->chat->send($this->dm, $this->a, 'oops');
    $this->actingAs($this->b)->deleteJson(route('messages.destroy', $m))->assertStatus(422);
    $this->actingAs($this->a)->deleteJson(route('messages.destroy', $m))->assertOk()
        ->assertJsonPath('message.deleted', true)->assertJsonPath('message.body', null);
    expect(Message::withTrashed()->find($m->id)->trashed())->toBeTrue();

    $late = $this->chat->send($this->dm, $this->a, 'keep');
    $this->travel(16)->minutes();
    $this->actingAs($this->a)->deleteJson(route('messages.destroy', $late))->assertStatus(422);

    $this->actingAs($this->b)->getJson(route('messages.history', ['conversation' => $this->dm->id, 'after' => 0]))
        ->assertOk()->assertJsonCount(2, 'messages')->assertJsonPath('messages.0.deleted', true);
});

it('marks a chat as read', function () {
    $this->chat->send($this->dm, $this->b, 'one');
    $two = $this->chat->send($this->dm, $this->b, 'two');
    expect($this->chat->unread($this->a))->toBe([$this->dm->id => 2]);

    Event::fake([ChatUpdated::class]);
    $this->actingAs($this->a)->postJson(route('messages.read', $this->dm), ['id' => $two->id + 50])->assertOk();
    expect($this->chat->unread($this->a))->toBe([])
        ->and(ConversationParticipant::where('conversation_id', $this->dm->id)->where('user_id', $this->a->id)->value('last_read_message_id'))->toBe($two->id);
    Event::assertDispatched(ChatUpdated::class, fn ($e) => $e->userId === $this->a->id);

    $this->actingAs($this->c)->postJson(route('messages.read', $this->dm), ['id' => $two->id])->assertForbidden();
});

it('pages through history: 40 older at a time, or everything after an id', function () {
    foreach (range(1, 45) as $i) {
        Message::create(['conversation_id' => $this->dm->id, 'user_id' => $this->b->id, 'type' => 'text', 'body' => "m$i"]);
    }
    $page = $this->actingAs($this->a)->get(route('messages.show', $this->dm))->assertInertia(fn (Assert $p) => $p->has('messages', 40)->where('hasMore', true)->where('messages.39.body', 'm45'));
    $ids = Message::where('conversation_id', $this->dm->id)->orderBy('id')->pluck('id');

    $this->getJson(route('messages.history', ['conversation' => $this->dm->id, 'before' => $ids[5]]))
        ->assertOk()->assertJsonCount(5, 'messages')->assertJsonPath('hasMore', false)->assertJsonPath('messages.0.body', 'm1');
    $this->getJson(route('messages.history', ['conversation' => $this->dm->id, 'after' => $ids[42]]))
        ->assertOk()->assertJsonCount(2, 'messages')->assertJsonPath('messages.1.body', 'm45');
    $this->actingAs($this->c)->getJson(route('messages.history', ['conversation' => $this->dm->id]))->assertForbidden();
});

it('opens a direct chat with friends, not with strangers or after a block', function () {
    $this->actingAs($this->a)->post(route('messages.direct', $this->b))->assertRedirect(route('messages.show', $this->dm));
    expect(Conversation::where('type', 'direct')->count())->toBe(1);

    $this->actingAs($this->a)->from('/somewhere')->post(route('messages.direct', $this->c))
        ->assertRedirect('/somewhere')->assertSessionHas('error', __('social.error.cannot_message'));

    app(Friends::class)->block($this->b, $this->a);
    $this->actingAs($this->a)->from('/somewhere')->post(route('messages.direct', $this->b))->assertRedirect('/somewhere')->assertSessionHas('error');
    $this->actingAs($this->a)->postJson(route('messages.send', $this->dm), ['body' => 'hi?'])->assertStatus(422);
});

it('broadcasts new, edited and deleted messages and list updates', function () {
    Event::fake([MessageSent::class, ChatUpdated::class]);
    $this->actingAs($this->a)->postJson(route('messages.send', $this->dm), ['body' => 'live'])->assertCreated();
    Event::assertDispatched(MessageSent::class, function (MessageSent $e) {
        $payload = $e->broadcastWith();

        return $e->action === 'new' && $e->broadcastAs() === 'message'
            && $e->broadcastOn()[0]->name === 'private-conversation.'.$this->dm->id
            && $payload['message']['body'] === 'live' && $payload['message']['user']['id'] === $this->a->id;
    });
    Event::assertDispatched(ChatUpdated::class, fn ($e) => $e->userId === $this->b->id && $e->broadcastOn()[0]->name === 'private-user.'.$this->b->id && $e->broadcastAs() === 'chat.updated');
    Event::assertDispatched(ChatUpdated::class, fn ($e) => $e->userId === $this->a->id);

    $m = Message::latest('id')->first();
    $this->patchJson(route('messages.update', $m), ['body' => 'live!'])->assertOk();
    Event::assertDispatched(MessageSent::class, fn ($e) => $e->action === 'edited' && $e->broadcastWith()['message']['body'] === 'live!');
    $this->deleteJson(route('messages.destroy', $m))->assertOk();
    Event::assertDispatched(MessageSent::class, fn ($e) => $e->action === 'deleted' && $e->broadcastWith()['message']['deleted'] === true);
});

it('authorizes the private channels for their owners and members only', function () {
    $channels = Broadcast::driver()->getChannels();
    $conv = $channels['conversation.{id}'];
    $user = $channels['user.{id}'];

    expect($conv($this->a, $this->dm->id))->toBe(['id' => $this->a->id, 'name' => $this->a->name])
        ->and($conv($this->c, $this->dm->id))->toBeFalse()
        ->and($conv($this->a, 999999))->toBeFalse()
        ->and($user($this->a, $this->a->id))->toBeTrue()
        ->and($user($this->a, $this->b->id))->toBeFalse();

    $this->chat->leave($this->dm, $this->b->id);
    expect($conv($this->b, $this->dm->id))->toBeFalse();
});

it('gives a game chat its pinned game bar: time, place and the field state (UX review item 9)', function () {
    [$friend] = User::factory()->count(1)->create();
    $creator = $this->a;
    app(Friends::class)->accept($friend, app(Friends::class)->request($creator, $friend));
    $game = app(Games::class)->create($creator, ['players_per_side' => 3, 'district' => 'arabkir'], [$this->b->id, $friend->id]);
    app(Games::class)->respond($game, $this->b, true);
    $conv = $game->fresh()->conversation;
    expect($conv)->not->toBeNull();

    $this->actingAs($this->a)->get(route('messages.show', $conv))->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('chat.game.id', $game->id)
        ->where('chat.game.window', null)
        ->where('chat.game.district', 'arabkir')
        ->where('chat.game.place', null)
        ->where('chat.game.booking', null)
    );
});

it('loads friends for the "New message" picker only when asked (UX review item 10)', function () {
    $this->actingAs($this->a)->get('/messages')->assertInertia(fn (Assert $p) => $p->missing('friends'));
    $this->actingAs($this->a)->get('/messages')->assertInertia(fn (Assert $p) => $p
        ->reloadOnly('friends', fn (Assert $r) => $r->has('friends', 1)->where('friends.0.id', $this->b->id))
    );
});
