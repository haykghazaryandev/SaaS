<?php

use App\Domain\Booking\Actions\RequestBooking;
use App\Domain\Chat\Chat;
use App\Domain\Notifications\BookingNotification;
use App\Domain\Notifications\PushNotice;
use App\Domain\Notifications\PushNotifications;
use App\Domain\Notifications\WebPushChannel;
use App\Models\Friendship;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Notice;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Notification;
use Minishlink\WebPush\VAPID;

function b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** A browser's subscription keys: a real P-256 public key (the payload is really encrypted) + a 16-byte secret. */
function browserKeys(): array
{
    $k = openssl_pkey_get_details(openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]))['ec'];

    return ['p256dh' => b64url("\x04".str_pad($k['x'], 32, "\0", STR_PAD_LEFT).str_pad($k['y'], 32, "\0", STR_PAD_LEFT)), 'auth' => b64url(random_bytes(16))];
}

function pushOn(): array
{
    $keys = VAPID::createVapidKeys();
    config(['webpush.vapid.public_key' => $keys['publicKey'], 'webpush.vapid.private_key' => $keys['privateKey'], 'webpush.vapid.subject' => 'mailto:test@zonespot.am']);

    return $keys;
}

function device(User $u, string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc'): PushSubscription
{
    $k = browserKeys();

    return app(PushNotifications::class)->subscribe($u, $endpoint, $k['p256dh'], $k['auth']);
}

function viaPush(User $u, object $n): bool
{
    return in_array(WebPushChannel::class, $n->via($u), true);
}

it('is off without VAPID keys: no subscribe route, no push channel, no key for the browser', function () {
    $u = User::factory()->create(['last_seen_at' => now()->subHour()]);
    device($u);

    $this->actingAs($u)->postJson('/me/push', ['endpoint' => 'https://push.example/x', 'keys' => browserKeys()])->assertNotFound();
    expect(viaPush($u, new Notice('booking_reminder_hour')))->toBeFalse();
    $this->actingAs($u)->get('/me/profile')->assertInertia(fn ($p) => $p->where('pushKey', null));
});

it('saves a device on subscribe and forgets it on unsubscribe', function () {
    $keys = pushOn();
    $u = User::factory()->create();
    $k = browserKeys();

    $this->postJson('/me/push', ['endpoint' => 'https://push.example/1', 'keys' => $k])->assertUnauthorized();
    $this->actingAs($u)->postJson('/me/push', ['endpoint' => 'http://push.example/1', 'keys' => $k])->assertJsonValidationErrors('endpoint');
    $this->actingAs($u)->postJson('/me/push', ['endpoint' => 'https://push.example/1', 'keys' => ['p256dh' => '<script>']])->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);

    $this->actingAs($u)->withHeader('User-Agent', 'Phone/1.0')
        ->postJson('/me/push', ['endpoint' => 'https://push.example/1', 'keys' => $k, 'contentEncoding' => 'aes128gcm'])->assertOk();
    $s = PushSubscription::sole();
    expect($s->user_id)->toBe($u->id)->and($s->public_key)->toBe($k['p256dh'])->and($s->user_agent)->toBe('Phone/1.0');
    $this->actingAs($u)->get('/me/profile')->assertInertia(fn ($p) => $p->where('pushKey', $keys['publicKey'])->where('pushEndpoints', ['https://push.example/1']));

    // the same browser, another person logged in: one row, now theirs
    $other = User::factory()->create();
    $this->actingAs($other)->postJson('/me/push', ['endpoint' => 'https://push.example/1', 'keys' => $k])->assertOk();
    expect(PushSubscription::sole()->user_id)->toBe($other->id);

    // someone else can't remove it
    $this->actingAs($u)->deleteJson('/me/push', ['endpoint' => 'https://push.example/1'])->assertOk();
    expect(PushSubscription::count())->toBe(1);
    $this->actingAs($other)->deleteJson('/me/push', ['endpoint' => 'https://push.example/1'])->assertOk();
    expect(PushSubscription::count())->toBe(0);
});

it('forgets this device on logout', function () {
    pushOn();
    $u = User::factory()->create();
    $this->actingAs($u)->postJson('/me/push', ['endpoint' => 'https://push.example/session', 'keys' => browserKeys()])->assertOk();
    $this->post('/logout')->assertRedirect();
    expect(PushSubscription::count())->toBe(0);

    // the endpoint sent by lib/push.ts with the logout (a session from before the subscribe)
    device($u, 'https://push.example/sent');
    device($u, 'https://push.example/other-phone');
    $this->actingAs($u)->post('/logout', ['push_endpoint' => 'https://push.example/sent']);
    expect(PushSubscription::pluck('endpoint')->all())->toBe(['https://push.example/other-phone']);
});

it('renews a replaced subscription from the service worker with the old endpoint as the proof', function () {
    pushOn();
    $u = User::factory()->create();
    device($u, 'https://push.example/old');
    $k = browserKeys();

    $this->postJson('/push/renew', ['old_endpoint' => 'https://push.example/unknown', 'endpoint' => 'https://push.example/new', 'keys' => $k])->assertNotFound();
    $this->postJson('/push/renew', ['old_endpoint' => 'https://push.example/old', 'endpoint' => 'https://push.example/new', 'keys' => $k])->assertOk();
    $s = PushSubscription::sole();
    expect($s->endpoint)->toBe('https://push.example/new')->and($s->public_key)->toBe($k['p256dh'])->and($s->user_id)->toBe($u->id);
});

it('pushes a notice only with a device, the keys and the switch on; social news only while the app is closed', function () {
    pushOn();
    $u = User::factory()->create(['last_seen_at' => now()->subMinutes(10)]);   // no Telegram at all
    expect(viaPush($u, new Notice('friend_request', ['name' => 'A'])))->toBeFalse();   // no device yet

    device($u);
    expect(viaPush($u, new Notice('friend_request', ['name' => 'A'])))->toBeTrue()
        ->and(viaPush($u, new Notice('booking_accepted')))->toBeFalse();   // bell only (not a Telegram type)

    $u->forceFill(['last_seen_at' => now()])->save();   // in the app: the bell and the toast show it
    expect(viaPush($u, new Notice('friend_request', ['name' => 'A'])))->toBeFalse()
        ->and(viaPush($u, new Notice('booking_reminder_hour')))->toBeTrue();   // bookings always

    $u->forceFill(['notify_prefs' => ['bookings' => false]])->save();
    expect(viaPush($u, new Notice('booking_reminder_hour')))->toBeFalse();

    config(['webpush.vapid.private_key' => null]);
    $u->forceFill(['notify_prefs' => [], 'last_seen_at' => now()->subHour()])->save();
    expect(viaPush($u, new Notice('friend_request', ['name' => 'A'])))->toBeFalse();
});

it('builds a plain-text payload in the recipient\'s language with a link on this site', function () {
    config(['app.url' => 'https://zonespot.am']);
    $u = User::factory()->create(['locale' => 'en']);
    $p = (new Notice('friend_request', ['name' => 'Aram <b>&</b>'], '/friends'))->toWebPush($u)->toArray();

    expect($p['title'])->toBe('ZoneSpot')
        ->and($p['body'])->toContain('Aram <b>&</b>')->not->toContain('&amp;')
        ->and($p['icon'])->toBe('/icons/icon-192.png')
        ->and($p['data']['url'])->toBe('/friends')
        ->and($p['tag'])->toStartWith('notice:friend_request:');
});

it('pushes booking news to the player and requests to staff without Telegram, by their switches', function () {
    pushOn();
    Notification::fake();
    $venue = makeVenue();
    $owner = User::factory()->create();
    $venue->staff()->attach($owner->id, ['role' => 'owner']);
    device($owner, 'https://push.example/owner');
    $player = User::factory()->create();
    device($player, 'https://push.example/player');

    $b = app(RequestBooking::class)->handle($player, $venue->fields->first(), at(20), 60);
    Notification::assertSentTo($owner, BookingNotification::class, fn ($n, $channels) => $n->audience === 'owner' && $channels === [WebPushChannel::class]);
    // the player's own "request sent": not by push (it was their tap)
    expect((new BookingNotification($b, 'requested', 'player'))->via($player))->toBe([])
        ->and((new BookingNotification($b, 'accepted', 'player'))->via($player))->toBe([WebPushChannel::class]);

    $owner->forceFill(['notify_prefs' => ['requests' => false]])->save();
    expect((new BookingNotification($b, 'requested', 'owner'))->via($owner))->toBe([]);
    $msg = (new BookingNotification($b, 'accepted', 'player'))->toWebPush($player)->toArray();
    expect($msg['body'])->not->toContain('<b>')->and($msg['data']['url'])->toEndWith('/me/bookings');
});

it('pushes chat messages to members away from the app, like the chat Telegram messages', function () {
    pushOn();
    Notification::fake();
    $a = User::factory()->create();
    $b = User::factory()->create(['last_seen_at' => now()->subHour()]);
    $off = User::factory()->create(['last_seen_at' => now()->subHour(), 'notify_prefs' => ['chats' => false]]);
    foreach ([$b, $off] as $x) {
        Friendship::create(['user_id' => $a->id, 'friend_id' => $x->id, 'status' => 'accepted', 'accepted_at' => now()]);
        device($x, 'https://push.example/'.$x->id);
    }
    app(Chat::class)->send(app(Chat::class)->direct($a, $b), $a, 'Who brings the ball?');
    app(Chat::class)->send(app(Chat::class)->direct($a, $off), $a, 'hello');

    Notification::assertSentTo($b, PushNotice::class, fn ($n) => str_contains($n->message->toArray()['body'], 'Who brings the ball?')
        && str_starts_with($n->message->toArray()['tag'], 'chat:'));
    Notification::assertNotSentTo($off, PushNotice::class);
});

it('does not push chat messages to someone with the app open', function () {
    pushOn();
    Notification::fake();
    $a = User::factory()->create();
    $b = User::factory()->create(['last_seen_at' => now()]);
    Friendship::create(['user_id' => $a->id, 'friend_id' => $b->id, 'status' => 'accepted', 'accepted_at' => now()]);
    device($b);
    app(Chat::class)->send(app(Chat::class)->direct($a, $b), $a, 'hi');

    Notification::assertNotSentTo($b, PushNotice::class);
});

/** A fake push service answering $responses in order; returns the list the sent requests are added to. */
function fakePushService(array $responses): ArrayObject
{
    $requests = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(fn (callable $next) => function ($request, $options) use ($next, $requests) {
        $requests[] = $request;

        return $next($request, $options);
    });
    config(['webpush.client_options' => ['handler' => $stack]]);   // the real client and encryption

    return $requests;
}

it('sends an encrypted push and deletes devices the push service says are gone (404 / 410)', function () {
    pushOn();
    $requests = fakePushService([new Response(201), new Response(410), new Response(404)]);

    $u = User::factory()->create(['last_seen_at' => now()->subHour()]);
    device($u, 'https://push.example/alive');
    device($u, 'https://push.example/gone');
    device($u, 'https://push.example/unknown');
    $u->notify(new Notice('friend_request', ['name' => 'Aram'], '/friends'));

    expect($requests)->toHaveCount(3)
        ->and($requests[0]->getHeaderLine('Content-Encoding'))->toBe('aes128gcm')
        ->and($requests[0]->getHeaderLine('Authorization'))->toStartWith('vapid t=')
        ->and(PushSubscription::pluck('endpoint')->all())->toBe(['https://push.example/alive']);
});

it('rejects keys the library could not encrypt for', function () {
    pushOn();
    $u = User::factory()->create();
    $k = browserKeys();
    $post = fn (array $keys) => $this->actingAs($u)->postJson('/me/push', ['endpoint' => 'https://push.example/k', 'keys' => $keys]);

    $post(['p256dh' => b64url("\x02".random_bytes(32)), 'auth' => $k['auth']])->assertJsonValidationErrors('keys.p256dh');   // compressed key
    $post(['p256dh' => b64url("\x04".random_bytes(32)), 'auth' => $k['auth']])->assertJsonValidationErrors('keys.p256dh');   // too short
    $post(['p256dh' => $k['p256dh'], 'auth' => b64url(random_bytes(8))])->assertJsonValidationErrors('keys.auth');
    $post(['p256dh' => 'not base64!', 'auth' => $k['auth']])->assertJsonValidationErrors('keys.p256dh');
    $this->actingAs($u)->postJson('/push/renew', ['old_endpoint' => 'https://push.example/k', 'endpoint' => 'https://push.example/k2',
        'keys' => ['p256dh' => $k['p256dh'], 'auth' => 'x']])->assertJsonValidationErrors('keys.auth');
    // standard base64 (with padding) is fine too
    $post(['p256dh' => strtr($k['p256dh'], '-_', '+/').'=', 'auth' => $k['auth']])->assertOk();
    expect(PushSubscription::count())->toBe(1);
});

it('only accepts endpoints on public hostnames (no requests into our network)', function (string $endpoint) {
    pushOn();
    $this->actingAs(User::factory()->create())->postJson('/me/push', ['endpoint' => $endpoint, 'keys' => browserKeys()])
        ->assertJsonValidationErrors('endpoint');
})->with([
    'http://fcm.googleapis.com/fcm/send/x', 'https://127.0.0.1/x', 'https://10.0.0.5/x', 'https://169.254.169.254/latest',
    'https://[::1]/x', 'https://[fd00::1]/x', 'https://localhost/x', 'https://app.localhost/x', 'https://web/x',
    'https://reverb.railway.internal:8080/x', 'https://printer.local/x', 'https://2130706433/x', 'https://0x7f.0.0.1/x',
    'https://user:pass@fcm.googleapis.com/x', 'ftp://fcm.googleapis.com/x',
]);

it('accepts the push services browsers use', function (string $endpoint) {
    pushOn();
    $this->actingAs(User::factory()->create())->postJson('/me/push', ['endpoint' => $endpoint, 'keys' => browserKeys()])->assertOk();
})->with([
    'https://fcm.googleapis.com/fcm/send/abc:def', 'https://updates.push.services.mozilla.com/wpush/v2/gAAA',
    'https://web.push.apple.com/QGx', 'https://wns2-par02p.notify.windows.com/w/?token=AQE%3d',
]);

it('still reaches the other devices when one subscription is broken, and drops the broken one', function () {
    pushOn();
    $requests = fakePushService([new Response(201)]);
    $u = User::factory()->create(['last_seen_at' => now()->subHour()]);
    // saved before the key checks existed: the library throws "only uncompressed keys are supported" for it
    PushSubscription::create(['user_id' => $u->id, 'endpoint' => 'https://push.example/broken', 'public_key' => b64url("\x02".random_bytes(32)), 'auth_token' => b64url(random_bytes(16))]);
    device($u, 'https://push.example/good');

    $u->notify(new Notice('friend_request', ['name' => 'Aram'], '/friends'));

    expect($requests)->toHaveCount(1)
        ->and((string) $requests[0]->getUri())->toBe('https://push.example/good')
        ->and(PushSubscription::pluck('endpoint')->all())->toBe(['https://push.example/good']);
});

it('leaves the player\'s phone number out of the staff push (the lock screen), Telegram keeps it', function () {
    $venue = makeVenue();
    $player = User::factory()->create(['phone' => '+37493111222', 'name' => 'Aram']);
    $b = app(RequestBooking::class)->handle($player, $venue->fields->first(), at(20), 60);
    $staff = User::factory()->create(['locale' => 'en']);
    $n = new BookingNotification($b->fresh(), 'requested', 'owner');

    expect($n->toWebPush($staff)->toArray()['body'])->toContain($b->contact_name)->not->toContain('111')
        ->and($n->toTelegram($staff)->text)->toContain('111');
});

it('keeps every subscription when sending fails on our side (VAPID config, the HTTP client)', function () {
    pushOn();
    $u = User::factory()->create(['last_seen_at' => now()->subHour()]);
    device($u, 'https://push.example/a');
    device($u, 'https://push.example/b');

    // a client that throws (not a push service answer): each device is tried, reported, kept
    $tried = new ArrayObject;
    $stack = HandlerStack::create(function ($request) use ($tried) {
        $tried[] = (string) $request->getUri();
        throw new RuntimeException('openssl / network stack broken');
    });
    config(['webpush.client_options' => ['handler' => $stack]]);
    $u->notify(new Notice('friend_request', ['name' => 'Aram'], '/friends'));
    expect($tried->getArrayCopy())->toBe(['https://push.example/a', 'https://push.example/b'])
        ->and(PushSubscription::count())->toBe(2);
});

it('keeps every subscription when the VAPID keys are broken: the job fails, rows untouched', function () {
    pushOn();
    $u = User::factory()->create(['last_seen_at' => now()->subHour()]);
    device($u, 'https://push.example/a');
    device($u, 'https://push.example/b');
    // the same exception class the library throws for a bad subscription key: rows go only when their own data is bad
    config(['webpush.vapid.private_key' => 'not-a-key']);
    expect(fn () => $u->notify(new Notice('friend_request', ['name' => 'Aram'], '/friends')))->toThrow(InvalidArgumentException::class)
        ->and(PushSubscription::count())->toBe(2);
});
