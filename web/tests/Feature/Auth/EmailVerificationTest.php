<?php

use App\Mail\EmailConfirmMail;
use App\Mail\LoginCodeMail;
use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;

/** The code in the last confirmation email sent to $to. */
function confirmCode(string $to): string
{
    $code = null;
    Mail::assertSent(EmailConfirmMail::class, function (EmailConfirmMail $m) use ($to, &$code) {
        if ($m->hasTo($to)) {
            $code = $m->code;
        }

        return $m->hasTo($to);
    });

    return $code;
}

function wrongCode(string $code): string
{
    return $code === '1234' ? '4321' : '1234';
}

beforeEach(fn () => Mail::fake());

// ---------- sign-up ----------

it('creates the account only when the code from the email is right', function () {
    $this->post('/login/register', ['email' => ' Ani@Mail.am ', 'password' => 'secret123', 'password_confirmation' => 'secret123'])
        ->assertRedirect()->assertSessionHasNoErrors();
    // nothing saved yet; the login page asks for the code sent to the address
    expect(User::where('email', 'ani@mail.am')->exists())->toBeFalse();
    $this->get('/login')->assertInertia(fn ($p) => $p->where('signup', 'ani@mail.am'));
    $code = confirmCode('ani@mail.am');

    $this->post('/login/register/verify', ['code' => wrongCode($code)])->assertSessionHasErrors('code');
    expect(User::where('email', 'ani@mail.am')->exists())->toBeFalse();

    $this->post('/login/register/verify', ['code' => $code])->assertRedirect(route('welcome'));
    $u = User::where('email', 'ani@mail.am')->first();
    expect($u->email_verified_at)->not->toBeNull()->and($u->password)->not->toBe('secret123');
    $this->assertAuthenticatedAs($u);
    // the password from step 1 works
    auth()->logout();
    $this->post('/login/password', ['email' => 'ani@mail.am', 'password' => 'secret123'])->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($u);
});

it('never creates an account when the address was taken while the code was on its way', function () {
    $this->post('/login/register', ['email' => 'race@mail.am', 'password' => 'secret123', 'password_confirmation' => 'secret123']);
    $code = confirmCode('race@mail.am');
    User::factory()->create(['email' => 'race@mail.am']);
    $this->post('/login/register/verify', ['code' => $code])->assertSessionHasErrors(['code' => __('auth.password.email_taken')]);
    expect(User::where('email', 'race@mail.am')->count())->toBe(1);
});

it('sends the sign-up code again, and Back forgets the sign-up', function () {
    $this->post('/login/register', ['email' => 'again@mail.am', 'password' => 'secret123', 'password_confirmation' => 'secret123']);
    $this->post('/login/register/resend')->assertSessionHasNoErrors();
    Mail::assertSent(EmailConfirmMail::class, 2);
    $this->post('/login/change');
    $this->get('/login')->assertInertia(fn ($p) => $p->where('signup', null));
    $this->post('/login/register/verify', ['code' => '1234'])->assertSessionHasErrors('code');
    expect(User::where('email', 'again@mail.am')->exists())->toBeFalse();
});

it('says so and creates nothing when the mail service fails', function () {
    Exceptions::fake();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));
    $this->post('/login/register', ['email' => 'nomail@mail.am', 'password' => 'secret123', 'password_confirmation' => 'secret123'])
        ->assertSessionHasErrors(['email' => __('auth.code.provider_error')]);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'SMTP down');
    // back on the sign-up form, not on a code screen
    $this->get('/login')->assertInertia(fn ($p) => $p->where('signup', null));
    expect(User::where('email', 'nomail@mail.am')->exists())->toBeFalse();
});

it('forgets an unfinished sign-up when an email login code is asked for', function () {
    $this->post('/login/register', ['email' => 'half@mail.am', 'password' => 'secret123', 'password_confirmation' => 'secret123']);
    $this->post('/login/email', ['email' => 'half@mail.am'])->assertSessionHasNoErrors();
    $this->get('/login')->assertInertia(fn ($p) => $p->where('signup', null)->where('email', 'half@mail.am'));
});

it('keeps the code already sent when sending a new one fails', function () {
    $this->post('/login/register', ['email' => 'keep@mail.am', 'password' => 'secret123', 'password_confirmation' => 'secret123']);
    $code = confirmCode('keep@mail.am');
    Exceptions::fake();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('Too many emails per second'));
    $this->post('/login/register/resend')->assertSessionHasErrors(['email' => __('auth.code.provider_error')]);
    $this->post('/login/register/verify', ['code' => $code])->assertRedirect(route('welcome'));
});

it('limits how many codes go to one address', function () {
    $this->post('/login/register', ['email' => 'many@mail.am', 'password' => 'secret123', 'password_confirmation' => 'secret123']);
    $this->post('/login/register/resend');
    $this->post('/login/register/resend');
    $this->post('/login/register/resend')->assertSessionHasErrors('email');
    Mail::assertSent(EmailConfirmMail::class, 3);
});

// ---------- Profile ----------

it('saves a new email in Profile only when its code is right', function () {
    $u = User::factory()->create(['email' => 'old@mail.am', 'email_verified_at' => now()]);
    $this->actingAs($u)->post('/me/email', ['email' => 'New@Mail.am'])->assertRedirect(route('me.email'));
    // still the old one, the new one waits for its code
    expect($u->fresh()->email)->toBe('old@mail.am');
    $this->get('/me/email')->assertInertia(fn ($p) => $p->component('profile/Email')->where('email', 'old@mail.am')->where('pending', 'new@mail.am'));
    $code = confirmCode('new@mail.am');

    $this->post('/me/email/verify', ['code' => wrongCode($code)])->assertSessionHasErrors('code');
    expect($u->fresh()->email)->toBe('old@mail.am');
    $this->post('/me/email/verify', ['code' => $code])->assertRedirect(route('me.profile'));
    expect($u->fresh()->email)->toBe('new@mail.am')->and($u->fresh()->email_verified_at)->not->toBeNull();
});

it('says so in Profile when the mail service fails, and nothing waits for a code', function () {
    Exceptions::fake();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down'));
    $u = User::factory()->create(['email' => null]);
    $this->actingAs($u)->post('/me/email', ['email' => 'new@mail.am'])->assertSessionHasErrors(['email' => __('auth.code.provider_error')]);
    $this->get('/me/email')->assertInertia(fn ($p) => $p->where('pending', null));
});

it('lets a Telegram / phone account add an email, never one another account has', function () {
    User::factory()->create(['email' => 'taken@mail.am']);
    $u = User::factory()->create(['email' => null, 'email_verified_at' => null]);
    $this->actingAs($u)->post('/me/email', ['email' => 'TAKEN@mail.am'])->assertSessionHasErrors(['email' => __('profile.email.taken')]);
    Mail::assertNothingSent();

    // a typo is harmless: nothing is saved until the code (which only the owner of that inbox gets) comes back
    $this->post('/me/email', ['email' => 'aram@gmal.com'])->assertSessionHasNoErrors();
    expect($u->fresh()->email)->toBeNull();
    $this->travel(61)->minutes();
    $this->post('/me/email/verify', ['code' => confirmCode('aram@gmal.com')])->assertSessionHasErrors(['code' => __('auth.code.expired')]);
    expect($u->fresh()->email)->toBeNull();
});

it('confirms an older account\'s unconfirmed email with a code', function () {
    $u = User::factory()->create(['email' => 'legacy@mail.am', 'email_verified_at' => null]);
    $this->actingAs($u)->get('/')->assertInertia(fn ($p) => $p->where('auth.user.emailConfirmed', false));
    $this->post('/me/email', ['email' => 'legacy@mail.am'])->assertSessionHasNoErrors();
    $this->post('/me/email/send')->assertSessionHasNoErrors();   // "Send again"
    $this->post('/me/email/verify', ['code' => confirmCode('legacy@mail.am')])->assertSessionHasNoErrors();
    expect($u->fresh()->email_verified_at)->not->toBeNull();
});

it('keeps links intact in the plain-text emails (no &amp;)', function () {
    $url = 'https://zonespot.com/login/reset/abc?email=a%40b.am&x=1';
    // the plain-text twin (the HTML one escapes & as &amp;, as HTML should)
    expect(view('mail.password-reset', ['url' => $url, 'minutes' => 60])->render())->toContain($url)->not->toContain('&amp;');
});

it('renders the HTML emails in every language: the code / link, the logo, and no raw text keys', function () {
    expect(public_path('images/email/logo-mark@2x.png'))->toBeFile();
    config(['app.url' => 'https://zonespot.com']);   // a public site: the hosted logo (localhost: see the next test)
    $url = 'https://zonespot.com/login/reset/abc?email=a%40b.am&x=1';
    foreach (['hy', 'ru', 'en'] as $locale) {
        app()->setLocale($locale);
        $mails = [
            '4821' => new EmailConfirmMail('4821'),
            '7305' => new LoginCodeMail('7305'),
            'href="'.e($url).'"' => new PasswordResetMail($url, 60),
        ];
        foreach ($mails as $must => $mail) {
            $html = $mail->locale($locale)->render();
            expect($html)->toContain('<html lang="'.$locale.'"')->toContain($must)
                // the logo: a hosted PNG by its full address (email apps cannot use a relative path)
                ->toContain('src="https://zonespot.com/images/email/logo-mark@2x.png"')
                ->not->toMatch('/\b(auth|common)\.[a-z_]+\.[a-z_]+/');   // a missing text shows its key
        }
    }
});

it('puts the logo inside the email on localhost, and links the hosted one on a public site', function () {
    $send = function (string $appUrl) {
        config(['app.url' => $appUrl]);
        // a real send through its own manager (Mail::fake replaced the app's one): the HTML is built with $message
        $mailer = (new MailManager(app()))->mailer('array');
        $mailer->to('ani@mail.am')->send(new EmailConfirmMail('4821'));

        return $mailer->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    };

    // a developer's computer: email apps cannot reach localhost, so the image travels inside the email
    $local = $send('http://localhost:8000');
    expect($local->getHtmlBody())->toContain('src="cid:')
        ->and(collect($local->getAttachments())->map(fn ($a) => $a->getFilename())->all())->toContain('logo-mark@2x.png');

    // staging / production: the hosted PNG, nothing attached
    $public = $send('https://zonespot.com');
    expect($public->getHtmlBody())->toContain('src="https://zonespot.com/images/email/logo-mark@2x.png"')
        ->and($public->getAttachments())->toBeEmpty();
});
