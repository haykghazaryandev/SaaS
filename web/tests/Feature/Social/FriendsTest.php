<?php

use App\Domain\Chat\Chat;
use App\Domain\Social\Friends;
use App\Domain\Social\SocialException;
use App\Models\Friendship;
use App\Models\User;

beforeEach(function () {
    $this->f = app(Friends::class);
    [$this->a, $this->b] = User::factory()->count(2)->create();
});

it('becomes friends after a request is accepted and notifies both', function () {
    $req = $this->f->request($this->a, $this->b);
    expect($req->status)->toBe('pending')->and($this->b->notifications()->count())->toBe(1);
    $this->f->accept($this->b, $req);
    expect($this->a->fresh()->isFriendOf($this->b))->toBeTrue()
        ->and($this->a->friendIds())->toBe([$this->b->id])
        ->and($this->f->relation($this->a, $this->b))->toBe('friends');
});

it('accepts automatically when both ask each other', function () {
    $this->f->request($this->a, $this->b);
    $this->f->request($this->b, $this->a);
    expect(Friendship::between($this->a->id, $this->b->id)->status)->toBe('accepted');
});

it('blocks: removes the friendship and stops requests and messages', function () {
    $this->f->accept($this->b, $this->f->request($this->a, $this->b));
    $this->f->block($this->b, $this->a);
    expect(Friendship::between($this->a->id, $this->b->id))->toBeNull()
        ->and(fn () => $this->f->request($this->a, $this->b))->toThrow(SocialException::class)
        ->and(app(Chat::class)->canMessage($this->a, $this->b))->toBeFalse();
});

it('lets friends message each other, not strangers', function () {
    $chat = app(Chat::class);
    expect(fn () => $chat->direct($this->a, $this->b))->toThrow(SocialException::class);
    $this->f->accept($this->b, $this->f->request($this->a, $this->b));
    $c = $chat->direct($this->a, $this->b);
    expect($chat->direct($this->b, $this->a)->id)->toBe($c->id);
    $chat->send($c, $this->a, 'Barev!');
    expect($chat->unread($this->b))->toBe([$c->id => 1])->and($chat->unread($this->a))->toBe([]);
    $chat->markRead($c, $this->b, $c->messages()->max('id'));
    expect($chat->unread($this->b))->toBe([]);
});
