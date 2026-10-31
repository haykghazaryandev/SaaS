<?php

namespace App\Http\Controllers;

use App\Domain\Auth\EmailCodes;
use App\Domain\Auth\EmailVerification;
use App\Domain\Auth\GoogleLogin;
use App\Domain\Auth\LoginLinks;
use App\Domain\Auth\PasswordLogin;
use App\Domain\Auth\PhoneCodes;
use App\Domain\Auth\TelegramLoginFlow;
use App\Domain\Auth\TelegramWebApp;
use App\Domain\Notifications\TelegramBot;
use App\Models\TelegramLogin;
use App\Models\User;
use App\Support\Locales;
use App\Support\Phone;
use App\Support\TestAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Login = registration. Ways to log in:
 * 1. "Log in with Telegram" (main, free): our bot gets the user's phone from Telegram → confirmed phone
 * 2. email code (backup for people without Telegram, free)
 * 3. phone + code (Telegram Gateway, paid ~$0.01): only when OTP_DRIVER=gateway, or in development (code on screen)
 * 4. email + password (sign up here too; "Forgot password?" emails a link)
 * 5. "Continue with Google" (when GOOGLE_CLIENT_ID is set)
 * New accounts finish on the welcome page (name; accounts without a phone add it there, RequirePhone keeps them there until then).
 * Owners use the same login; their accounts are created by the admin.
 */
class AuthController extends Controller
{
    public function show(Request $request, TelegramBot $bot)
    {
        // ?redirect=/places/zangak?… brings the person back after logging in (only paths on this site)
        $back = (string) $request->query('redirect');
        if (str_starts_with($back, '/') && ! str_starts_with($back, '//')) {
            $request->session()->put('url.intended', url($back));
        }

        return Inertia::render('auth/Login', [
            'to' => $request->query('to'),
            'phone' => $request->session()->get('otp_phone') ? Phone::format($request->session()->get('otp_phone')) : null,
            'email' => $request->session()->get('otp_email'),
            'signup' => $request->session()->get('signup.email'),
            'telegram' => ['bot' => config('services.telegram.bot_username'), 'simulate' => $this->canSimulate($bot)],
            'phoneCode' => $this->phoneCodeEnabled(),
            'testAccounts' => ! $this->realPhoneCodes() && TestAccounts::enabled(),
            // email codes need a real mail service (MAIL_MAILER); with "log" nobody would receive them
            'emailLogin' => config('app.debug') || ! in_array(config('mail.default'), ['log', 'array'], true),
            'google' => $this->googleEnabled(),
        ]);
    }

    // ---------- 1. Log in with Telegram ----------

    /** Starts a Telegram login; the browser opens the deep link and polls the status. */
    public function telegramStart(Request $request, TelegramLoginFlow $flow, TelegramBot $bot)
    {
        $login = $flow->start();
        $request->session()->put('tg_logins', [...array_slice($request->session()->get('tg_logins', []), -4), $login->token]);

        return response()->json(['token' => $login->token, 'url' => $flow->deepLink($login), 'simulate' => $this->canSimulate($bot), 'expires_in' => TelegramLoginFlow::MINUTES * 60]);
    }

    public function telegramStatus(Request $request, string $token, TelegramLoginFlow $flow)
    {
        return response()->json(['status' => $flow->status($this->ownLogin($request, $token))]);
    }

    /** The bot approved the login: log this browser in. $request->context = 'booking' keeps the person on the page. */
    public function telegramComplete(Request $request, string $token, TelegramLoginFlow $flow)
    {
        $user = $flow->complete($this->ownLogin($request, $token));
        if (! $user) {
            return response()->json(['error' => __('auth.telegram.expired')], 422);
        }
        if ($user->isBlocked()) {
            return response()->json(['error' => __('auth.blocked')], 422);
        }
        $request->session()->put('tg_logins', array_values(array_diff($request->session()->get('tg_logins', []), [$token])));
        // first login from the Mini App: speak the phone's language (until the person picks one in the app)
        if (($lang = $request->session()->pull('device_lang')) && ! $user->locale_chosen_at && $user->locale !== $lang) {
            $user->forceFill(['locale' => $lang])->save();
        }

        $redirect = $this->landing($request, $user);   // logs in; the booking sheet stays on its page

        return response()->json(['redirect' => $request->input('context') === 'booking' ? null : $redirect]);
    }

    /**
     * Telegram Mini App: the page sends Telegram's signed initData. A known Telegram account is logged in at once
     * (no login screen); a new one shares its phone once with the bot (the usual contact step), then the page
     * polls and completes like "Log in with Telegram". Returns status ok | need_contact.
     */
    public function telegramApp(Request $request, TelegramWebApp $app, TelegramLoginFlow $flow)
    {
        $data = $app->verify((string) $request->input('init_data'));
        if (! $data) {
            return response()->json(['error' => __('auth.telegram.app_bad')], 422);
        }
        $tgId = (int) $data['user']['id'];   // in a private chat the chat id is the user id
        // where to go: a t.me link's page, else the page the person wanted before being sent to /login, else this page
        $intended = (string) parse_url((string) $request->session()->pull('url.intended'), PHP_URL_PATH);
        $here = LoginLinks::safePath($request->input('path'));
        $path = $data['start_param'] ? TelegramWebApp::startPath($data['start_param'])
            : (in_array(strtok($here, '?'), ['/login', '/welcome'], true) ? LoginLinks::safePath($intended ?: '/') : $here);
        // only a Telegram account confirmed by its own phone for this account (never one linked without the phone)
        $user = User::where('telegram_chat_id', $tgId)->whereNotNull('telegram_verified_at')->first();

        if ($user) {
            if ($user->isBlocked()) {
                return response()->json(['error' => __('auth.blocked')], 422);
            }
            if (! $request->user()?->is($user)) {
                if ($request->user()) {
                    Auth::guard('web')->logout();
                }
                Auth::login($user, remember: true);
                $request->session()->regenerate();
            }
            // until the person picks a language in the app: the phone's language, else Telegram's
            $lang = $user->locale_chosen_at ? null : (Locales::match($request->input('device_lang')) ?? Locales::match($data['user']['language_code'] ?? null));
            $user->forceFill(['last_seen_at' => now()] + ($lang ? ['locale' => $lang] : []))->save();
            $user->syncAdmin();

            return response()->json(['status' => 'ok', 'user_id' => $user->id, 'locale' => $user->preferredLocale(), 'redirect' => $user->isRegistered() && $user->phone ? $path : route('welcome', [], false)]);
        }

        // first time: the bot asks for the phone (Telegram.WebApp.requestContact sends the contact to the bot)
        $login = $flow->start();
        $login->update(['chat_id' => $tgId]);
        $request->session()->put('tg_logins', [...array_slice($request->session()->get('tg_logins', []), -4), $login->token]);
        $request->session()->put('url.intended', url($path));
        $request->session()->put('device_lang', Locales::match($request->input('device_lang')));   // used once the login completes

        return response()->json(['status' => 'need_contact', 'token' => $login->token]);
    }

    /** Development only (no bot token): pretend the user shared this phone in Telegram. */
    public function telegramSimulate(Request $request, string $token, TelegramLoginFlow $flow, TelegramBot $bot)
    {
        abort_unless($this->canSimulate($bot), 404);
        $phone = Phone::normalize($request->input('phone'));
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => __('auth.phone.invalid')]);
        }
        $flow->simulate($this->ownLogin($request, $token), $phone, $request->input('name') ?: null);

        return response()->json(['status' => 'approved']);
    }

    /** A link from our Telegram bot: log in as that person and open the page. */
    public function loginLink(Request $request, LoginLinks $links)
    {
        $to = LoginLinks::safePath($request->query('to'));
        $user = $links->consume($request);
        if (! $user) {
            // expired or used: if this browser is logged in, just open the page; otherwise log in first
            return $request->user() ? redirect($to) : redirect()->route('login', ['redirect' => $to]);
        }
        if (! $request->user()?->is($user)) {
            if ($request->user()) {
                Auth::guard('web')->logout();
            }
            Auth::login($user, remember: true);
            $request->session()->regenerate();
        }
        $user->forceFill(['last_seen_at' => now()])->save();
        if (! $user->isRegistered() || ! $user->phone) {
            $request->session()->put('url.intended', url($to));

            return redirect()->route('welcome');
        }

        return str_starts_with($to, '/admin') && ! $user->is_admin ? redirect('/') : redirect($to);
    }

    // ---------- 2. email code ----------

    public function emailSend(Request $request, EmailCodes $codes)
    {
        $data = $request->validate(['email' => 'required|email:rfc|max:120']);
        $codes->send($data['email'], $request->ip());
        $request->session()->forget('signup');
        $request->session()->put('otp_email', PasswordLogin::normalize($data['email']));

        return back();
    }

    public function emailVerify(Request $request, EmailCodes $codes)
    {
        $request->validate(['code' => 'required|digits:4']);
        $email = $request->session()->get('otp_email');
        if (! $email) {
            throw ValidationException::withMessages(['code' => __('auth.code.expired')]);
        }
        $user = $codes->verify($email, $request->input('code'));
        $request->session()->forget('otp_email');

        return $this->finish($request, $user);
    }

    // ---------- 3. phone + code (Telegram Gateway; development shows the code) ----------

    public function sendCode(Request $request, PhoneCodes $codes)
    {
        abort_unless($this->phoneCodeEnabled(), 404);
        $request->validate(['phone' => 'required|string|max:20']);
        $phone = Phone::normalize($request->input('phone'));
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => __('auth.phone.invalid')]);
        }
        // without the paid Gateway (staging): only the demo accounts, their code is shown on screen
        if (! $this->realPhoneCodes() && ! TestAccounts::is($phone)) {
            throw ValidationException::withMessages(['phone' => __('auth.phone.test_only')]);
        }
        $codes->send($phone, $request->ip());
        $request->session()->forget('signup');
        $request->session()->put('otp_phone', $phone);

        return back();
    }

    public function verify(Request $request, PhoneCodes $codes)
    {
        abort_unless($this->phoneCodeEnabled(), 404);
        $request->validate(['code' => 'required|digits:4']);
        $phone = $request->session()->get('otp_phone');
        if (! $phone) {
            throw ValidationException::withMessages(['code' => __('auth.code.expired')]);
        }
        $user = $codes->verify($phone, $request->input('code'));
        $request->session()->forget('otp_phone');

        return $this->finish($request, $user);
    }

    public function changePhone(Request $request)
    {
        $request->session()->forget(['otp_phone', 'otp_email', 'signup']);

        return back();
    }

    // ---------- 4. email + password ----------

    public function passwordLogin(Request $request, PasswordLogin $login)
    {
        $data = $request->validate(['email' => 'required|email:rfc|max:120', 'password' => 'required|string|max:200']);

        return $this->finish($request, $login->attempt($data['email'], $data['password'], (string) $request->ip()), 'email');
    }

    /** Sign up, step 1: email and password → a code to the email; the account comes with the right code. */
    public function passwordRegister(Request $request, EmailVerification $verification)
    {
        $request->merge(['email' => PasswordLogin::normalize((string) $request->input('email'))]);
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:120', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'max:200', 'confirmed', Password::min(8)->letters()->numbers()],
        ], ['email.unique' => __('auth.password.email_taken')]);

        $request->session()->forget(['otp_phone', 'otp_email', 'signup']);
        $request->session()->put('signup', $verification->startSignup($data['email'], $data['password'], (string) $request->ip()));

        return back();
    }

    /** Sign-up, step 2: the code from the email → the account is created, then the welcome page. */
    public function passwordRegisterVerify(Request $request, EmailVerification $verification)
    {
        $request->validate(['code' => 'required|digits:4']);
        $signup = $request->session()->get('signup');
        if (! $signup) {
            throw ValidationException::withMessages(['code' => __('auth.code.expired')]);
        }
        try {
            $user = $verification->finishSignup($signup, $request->input('code'));
        } catch (ValidationException $e) {
            if (! $verification->signupPending($signup)) {
                $request->session()->forget('signup');
            }
            throw $e;
        }

        return $this->finish($request, $user);
    }

    public function passwordRegisterResend(Request $request, EmailVerification $verification)
    {
        $signup = $request->session()->get('signup');
        abort_unless($signup, 404);
        $verification->resendSignup($signup, (string) $request->ip());

        return back();
    }

    /** "Forgot password?": the same answer whether the email has an account or not. */
    public function passwordForgot(Request $request, PasswordLogin $login)
    {
        $data = $request->validate(['email' => 'required|email:rfc|max:120']);
        $login->sendResetLink($data['email']);

        return back()->with('success', __('auth.reset.sent'));
    }

    /** The link from the email: choose a new password. */
    public function passwordResetForm(Request $request, string $token)
    {
        return Inertia::render('auth/ResetPassword', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function passwordReset(Request $request, PasswordLogin $login)
    {
        $data = $request->validate([
            'token' => 'required|string|max:200',
            'email' => 'required|email:rfc|max:120',
            'password' => ['required', 'string', 'max:200', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        $user = $login->reset($data['email'], $data['token'], $data['password']);
        $request->session()->flash('success', __('auth.reset.done'));

        return $this->finish($request, $user, 'password');
    }

    // ---------- 5. Google ----------

    public function googleRedirect(Request $request)
    {
        abort_unless($this->googleEnabled(), 404);
        $request->session()->put('google_to', $request->query('to'));

        return Socialite::driver('google')->redirect();
    }

    public function googleCallback(Request $request, GoogleLogin $google)
    {
        abort_unless($this->googleEnabled(), 404);
        try {
            $user = $google->user(Socialite::driver('google')->user());
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('login')->with('error', __('auth.google.failed'));
        }
        if ($user->isBlocked()) {
            return redirect()->route('login')->with('error', __('auth.blocked'));
        }
        $request->merge(['to' => $request->session()->pull('google_to')]);

        // a plain browser redirect from Google (not an Inertia visit)
        return redirect($this->landing($request, $user));
    }

    // ---------- welcome (registration) ----------

    /** First login: name and username; email accounts also give their phone (not confirmed). */
    public function welcome(Request $request)
    {
        $user = $request->user();
        if ($user->isRegistered()) {
            return redirect()->route('home');
        }

        return Inertia::render('auth/Welcome', ['name' => $user->name, 'username' => $user->username]);
    }

    public function register(Request $request)
    {
        $user = $request->user();
        // usernames are case-insensitive: lowercase before the unique check
        $request->merge(['username' => $request->filled('username') ? strtolower(trim($request->input('username'))) : null]);
        $data = $request->validate([
            'name' => 'required|string|min:2|max:60',
            'username' => ['nullable', 'string', 'min:3', 'max:32', 'regex:/^[a-z0-9_.]+$/i', Rule::unique('users')->ignore($user->id)],
        ]);
        $user->forceFill([
            'name' => $data['name'],
            'username' => $data['username'] ?? null, 'registered_at' => $user->registered_at ?? now(), 'locale' => app()->getLocale(),
        ])->save();
        // no confirmed phone yet (email, Google): confirm it with Telegram now, or later before booking
        if (! $user->phone_verified_at) {
            return redirect()->route('phone.confirm');
        }

        return redirect()->intended(route('home'))->with('success', __('auth.welcome_done'));
    }

    /** "Confirm your phone": with Telegram (the bot takes the number of that Telegram account); needed to book. */
    public function confirmPhone(Request $request)
    {
        $user = $request->user();
        // ?back=/t/abc: a page that sent the person here on purpose (only our own paths)
        $back = (string) $request->query('back');
        if (str_starts_with($back, '/') && ! str_starts_with($back, '//')) {
            $request->session()->put('url.intended', url($back));
        }
        if ($user->phone_verified_at) {
            return redirect()->intended(route('home'));
        }

        return Inertia::render('auth/ConfirmPhone', ['back' => $request->session()->get('url.intended')]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    // ---------- helpers ----------

    /** Log in and go to the right page (Inertia redirect). */
    private function finish(Request $request, User $user, string $errorField = 'code')
    {
        if ($user->isBlocked()) {
            throw ValidationException::withMessages([$errorField => __('auth.blocked')]);
        }
        $url = $this->landing($request, $user);
        // the admin panel (Filament) is not an Inertia page: full page load
        if (str_starts_with((string) parse_url($url, PHP_URL_PATH), '/admin')) {
            return Inertia::location($url);
        }

        return redirect($url);
    }

    /** Logs the user in and returns where they should land. */
    private function landing(Request $request, User $user): string
    {
        Auth::login($user, remember: true);
        $request->session()->regenerate();
        $request->session()->forget(['signup', 'otp_email', 'otp_phone']);
        if ($request->input('to') === 'owner' && $user->venues()->exists()) {
            return route('owner.start');
        }
        $intended = (string) $request->session()->pull('url.intended');
        if (! $user->isRegistered() || ! $user->phone) {
            if ($intended) {
                $request->session()->put('url.intended', $intended);
            }

            return route('welcome');
        }
        if ($intended && (! str_starts_with((string) parse_url($intended, PHP_URL_PATH), '/admin') || $user->is_admin)) {
            return $intended;
        }

        return route('home');
    }

    /** The login must have been started in this browser (its token is in this session). */
    private function ownLogin(Request $request, string $token): TelegramLogin
    {
        abort_unless(in_array($token, $request->session()->get('tg_logins', []), true), 404);

        return TelegramLogin::where('token', $token)->firstOrFail();
    }

    private function canSimulate(TelegramBot $bot): bool
    {
        return config('app.debug') && ! $bot->configured();
    }

    private function googleEnabled(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    private function phoneCodeEnabled(): bool
    {
        return $this->realPhoneCodes() || TestAccounts::enabled();
    }

    /** Codes to any phone: the paid Telegram Gateway, or development (code on screen). */
    private function realPhoneCodes(): bool
    {
        return config('services.otp_driver') === 'gateway' || config('app.debug');
    }
}
