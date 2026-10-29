<?php

use App\Domain\Booking\Actions\RequestBooking;
use App\Domain\Booking\BookingException;
use App\Domain\Notifications\TelegramLinker;
use App\Mail\PasswordResetMail;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;

function googleUser(array $raw = [], ?string $email = 'ani@gmail.com'): GoogleUser
{
    return (new GoogleUser)->setRaw($raw + ['email_verified' => true])->map(['id' => '1001', 'name' => 'Ani Hakobyan', 'email' => $email]);
}

beforeEach(fn () => config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']));

// ---------- email + password ----------

it('signs up with email and password, then asks for the name; booking waits for a phone confirmed with Telegram', function () {
    $small = makeVenue()->fields->firstWhere('size.value', 'small');
    $booking = ['field_id' => $small->id, 'date' => at(0)->toDateString(), 'start' => '20:00', 'minutes' => 60];
    $this->post('/login/register', ['email' => ' Aram@Mail.am ', 'password' => 'secret123', 'password_confirmation' => 'secret123'])
        ->assertRedirect(route('welcome'));
    $u = User::where('email', 'aram@mail.am')->first();
    expect($u)->not->toBeNull()->and($u->username)->toBeNull()->and($u->phone)->toBeNull()->and($u->isRegistered())->toBeFalse()
        ->and($u->password)->not->toBe('secret123');   // hashed

    // the name (the username is optional), then "Confirm your phone"
    $this->post('/welcome', ['name' => 'Aram', 'username' => ''])->assertRedirect(route('phone.confirm'));
    expect($u->fresh()->isRegistered())->toBeTrue();
    // looking around is open; booking needs the confirmed phone (back to the page afterwards)
    $this->get('/')->assertOk()->assertInertia(fn ($p) => $p->where('auth.user.phoneConfirmed', false));
    $this->get('/me/bookings')->assertOk();
    $this->from('/places/zangak')->post('/bookings', $booking)->assertRedirect(route('phone.confirm'));
    $this->postJson('/bookings', $booking)->assertStatus(409)->assertJsonPath('redirect', route('phone.confirm'));
    expect(Booking::count())->toBe(0);
    $this->get('/confirm-phone')->assertOk()->assertInertia(fn ($p) => $p->component('auth/ConfirmPhone')->where('back', url('/places/zangak')));

    // "Connect Telegram": the bot gets the number of that Telegram account: it becomes the confirmed phone
    $linker = app(TelegramLinker::class);
    $linker->start($linker->createToken($u->fresh())->token, 555001);
    expect($linker->finish(555001, '+37491123456')[0])->toBe('linked')
        ->and($u->fresh()->phone)->toBe('+37491123456')->and($u->fresh()->phone_verified_at)->not->toBeNull()->and($u->fresh()->telegram_chat_id)->toBe(555001);
    // now the booking goes through
    $this->actingAs($u->fresh())->post('/bookings', $booking)->assertSessionHasNoErrors();
    expect(Booking::where('user_id', $u->id)->count())->toBe(1);
});

it('never gives an email account a number another account already confirmed', function () {
    User::factory()->create(['phone' => '+37491999888', 'phone_verified_at' => now()]);
    $u = User::factory()->create(['phone' => null, 'phone_verified_at' => null, 'email' => 'x@mail.am']);
    $linker = app(TelegramLinker::class);
    $linker->start($linker->createToken($u)->token, 555002);
    expect($linker->finish(555002, '+37491999888')[0])->toBe('taken')->and($u->fresh()->phone)->toBeNull();
});

it('checks the sign-up form', function () {
    User::factory()->create(['email' => 'davo@mail.am']);
    $ok = ['email' => 'new@mail.am', 'password' => 'secret123', 'password_confirmation' => 'secret123'];
    $this->post('/login/register', ['email' => ''] + $ok)->assertSessionHasErrors('email');
    $this->post('/login/register', ['email' => 'not-an-email'] + $ok)->assertSessionHasErrors('email');
    $this->post('/login/register', ['email' => 'Davo@Mail.am'] + $ok)->assertSessionHasErrors(['email' => __('auth.password.email_taken')]);
    $this->post('/login/register', ['password' => 'short1', 'password_confirmation' => 'short1'] + $ok)->assertSessionHasErrors('password');
    $this->post('/login/register', ['password' => 'onlyletters', 'password_confirmation' => 'onlyletters'] + $ok)->assertSessionHasErrors('password');
    $this->post('/login/register', ['password_confirmation' => 'other123'] + $ok)->assertSessionHasErrors('password');
    $this->assertGuest();
});

it('logs in with the email and the password', function () {
    $u = User::factory()->create(['email' => 'aram@mail.am', 'password' => 'secret123']);
    $this->post('/login/password', ['email' => ' ARAM@mail.am ', 'password' => 'secret123'])->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($u);
});

it('gives the same answer for a wrong password and an unknown email, and stops after 5 tries', function () {
    User::factory()->create(['email' => 'aram@mail.am', 'password' => 'secret123']);
    User::factory()->create(['email' => 'nopass@mail.am']);   // an email-code account: no password yet
    $this->post('/login/password', ['email' => 'aram@mail.am', 'password' => 'wrong'])->assertSessionHasErrors(['email' => __('auth.password.wrong')]);
    $this->post('/login/password', ['email' => 'ghost@mail.am', 'password' => 'wrong'])->assertSessionHasErrors(['email' => __('auth.password.wrong')]);
    $this->post('/login/password', ['email' => 'nopass@mail.am', 'password' => 'anything1'])->assertSessionHasErrors(['email' => __('auth.password.wrong')]);
    foreach (range(1, 4) as $i) {
        $this->post('/login/password', ['email' => 'aram@mail.am', 'password' => 'wrong']);
    }
    $this->post('/login/password', ['email' => 'aram@mail.am', 'password' => 'secret123'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('sends an owner to the owner panel after a password login', function () {
    $owner = User::factory()->create(['email' => 'zangak@mail.am', 'password' => 'secret123']);
    makeVenue()->staff()->attach($owner->id, ['role' => 'owner']);
    $this->post('/login/password', ['email' => 'zangak@mail.am', 'password' => 'secret123', 'to' => 'owner'])->assertRedirect(route('owner.start'));
});

// ---------- forgot password ----------

it('emails a reset link and sets the new password from it', function () {
    Mail::fake();
    $u = User::factory()->create(['email' => 'aram@mail.am', 'password' => 'oldpass123', 'locale' => 'hy']);
    $this->post('/login/forgot', ['email' => 'Aram@Mail.am'])->assertSessionHas('success', __('auth.reset.sent'));

    $url = null;
    Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $m) use (&$url) {
        $url = $m->url;

        return $m->hasTo('aram@mail.am') && $m->locale === 'hy';
    });
    $this->get($url)->assertOk()->assertInertia(fn ($p) => $p->component('auth/ResetPassword')->where('email', 'aram@mail.am'));

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $token = basename((string) parse_url($url, PHP_URL_PATH));
    $this->post('/login/reset', ['token' => $token, 'email' => $query['email'], 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
        ->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($u);

    // the link works once; the old password is gone, the new one works
    auth()->logout();
    $this->post('/login/reset', ['token' => $token, 'email' => 'aram@mail.am', 'password' => 'other123x', 'password_confirmation' => 'other123x'])
        ->assertSessionHasErrors(['password' => __('auth.reset.invalid')]);
    $this->post('/login/password', ['email' => 'aram@mail.am', 'password' => 'oldpass123'])->assertSessionHasErrors('email');
    $this->post('/login/password', ['email' => 'aram@mail.am', 'password' => 'newpass123'])->assertRedirect(route('home'));
});

it('gives the same answer for an unknown email and sends nothing', function () {
    Mail::fake();
    $this->post('/login/forgot', ['email' => 'nobody@mail.am'])->assertSessionHas('success', __('auth.reset.sent'));
    Mail::assertNothingSent();
});

it('rejects a wrong reset token', function () {
    User::factory()->create(['email' => 'aram@mail.am', 'password' => 'oldpass123']);
    $this->post('/login/reset', ['token' => 'wrong', 'email' => 'aram@mail.am', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
        ->assertSessionHasErrors(['password' => __('auth.reset.invalid')]);
    $this->assertGuest();
});

it('lets an email-code account set its first password and log in with the email', function () {
    Mail::fake();
    $u = User::factory()->create(['email' => 'narek@mail.am', 'email_verified_at' => null]);
    $this->post('/login/forgot', ['email' => 'narek@mail.am']);
    Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $m) use (&$url) {
        $url = $m->url;

        return true;
    });
    $token = basename((string) parse_url($url, PHP_URL_PATH));
    $this->post('/login/reset', ['token' => $token, 'email' => 'narek@mail.am', 'password' => 'newpass123', 'password_confirmation' => 'newpass123']);
    expect($u->fresh()->email_verified_at)->not->toBeNull();
    auth()->logout();
    $this->post('/login/password', ['email' => 'narek@mail.am', 'password' => 'newpass123'])->assertRedirect(route('home'));
});

// ---------- Google ----------

it('hides Google when it is not configured', function () {
    config(['services.google.client_id' => null]);
    $this->get('/login')->assertInertia(fn ($p) => $p->where('google', false));
    $this->get('/login/google')->assertNotFound();
});

it('creates an account with Google; it can look around before the phone is confirmed', function () {
    $this->get('/login')->assertInertia(fn ($p) => $p->where('google', true));
    $this->get('/login/google')->assertRedirect();

    Socialite::fake('google', googleUser());
    $this->get('/login/google/callback')->assertRedirect(route('welcome'));
    $u = User::where('google_id', '1001')->first();
    expect($u->email)->toBe('ani@gmail.com')->and($u->email_verified_at)->not->toBeNull()->and($u->name)->toBe('Ani Hakobyan')->and($u->phone)->toBeNull();
    $this->get('/me/cards')->assertOk();

    // the next time: the same account
    auth()->logout();
    $this->get('/login/google/callback');
    expect(User::count())->toBe(1);
});

it('links Google to the account with the same confirmed email', function () {
    $u = User::factory()->create(['email' => 'ani@gmail.com']);
    Socialite::fake('google', googleUser());
    $this->get('/login/google/callback')->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($u);
    expect($u->fresh()->google_id)->toBe('1001');
});

it('does not link Google to an account by an email Google did not confirm', function () {
    $u = User::factory()->create(['email' => 'ani@gmail.com']);
    Socialite::fake('google', googleUser(['email_verified' => false]));
    $this->get('/login/google/callback')->assertRedirect(route('welcome'));
    expect(auth()->id())->not->toBe($u->id)->and(User::where('google_id', '1001')->first()->email)->toBeNull();
});

it('goes back to the login page when Google fails', function () {
    Socialite::shouldReceive('driver->user')->andThrow(new InvalidStateException);
    $this->get('/login/google/callback')->assertRedirect(route('login'))->assertSessionHas('error', __('auth.google.failed'));
    $this->assertGuest();
});

it('does not let a blocked account in with Google', function () {
    User::factory()->create(['google_id' => '1001', 'status' => 'blocked']);
    Socialite::fake('google', googleUser());
    $this->get('/login/google/callback')->assertRedirect(route('login'))->assertSessionHas('error', __('auth.blocked'));
    $this->assertGuest();
});

it('never books for an account without a confirmed phone, whatever the way in', function () {
    $field = makeVenue()->fields->first();
    foreach ([['phone' => null, 'phone_verified_at' => null], ['phone' => '+37491777666', 'phone_verified_at' => null]] as $attrs) {
        $u = User::factory()->create($attrs + ['email' => uniqid().'@mail.am']);
        expect(fn () => app(RequestBooking::class)->handle($u, $field, at(20), 60))
            ->toThrow(BookingException::class);
    }
    expect(Booking::count())->toBe(0);
});
