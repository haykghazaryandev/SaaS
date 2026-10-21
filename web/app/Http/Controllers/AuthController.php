<?php

namespace App\Http\Controllers;

use App\Domain\Auth\PhoneCodes;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Login and registration are the same flow: phone → code from Telegram → (first time) name.
 * Owners use the same login; their accounts are created by the admin.
 */
class AuthController extends Controller
{
    public function show(Request $request)
    {
        return Inertia::render('auth/Login', [
            'to' => $request->query('to'),
            'phone' => $request->session()->get('otp_phone') ? Phone::format($request->session()->get('otp_phone')) : null,
        ]);
    }

    public function sendCode(Request $request, PhoneCodes $codes)
    {
        $request->validate(['phone' => 'required|string|max:20']);
        $phone = Phone::normalize($request->input('phone'));
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => __('auth.phone.invalid')]);
        }
        $codes->send($phone, $request->ip());
        $request->session()->put('otp_phone', $phone);

        return back();
    }

    public function verify(Request $request, PhoneCodes $codes)
    {
        $request->validate(['code' => 'required|digits:4']);
        $phone = $request->session()->get('otp_phone');
        if (! $phone) {
            throw ValidationException::withMessages(['code' => __('auth.code.expired')]);
        }
        $user = $codes->verify($phone, $request->input('code'));
        if ($user->isBlocked()) {
            throw ValidationException::withMessages(['code' => __('auth.blocked')]);
        }
        Auth::login($user, remember: true);
        $request->session()->regenerate();
        $request->session()->forget('otp_phone');
        if ($request->input('to') === 'owner' && $user->venues()->exists()) {
            return redirect()->route('owner.today');
        }
        // back to the admin panel (Filament, not an Inertia page): a full page load instead of an Inertia visit
        $intended = (string) $request->session()->get('url.intended');
        if ($user->is_admin && str_starts_with((string) parse_url($intended, PHP_URL_PATH), '/admin')) {
            $request->session()->forget('url.intended');

            return Inertia::location($intended);
        }
        if (! $user->isRegistered()) {
            return redirect()->route('welcome');
        }

        return redirect()->intended(route('home'));
    }

    public function changePhone(Request $request)
    {
        $request->session()->forget('otp_phone');

        return back();
    }

    /** First login: name and username (registration). */
    public function welcome(Request $request)
    {
        if ($request->user()->isRegistered()) {
            return redirect()->route('home');
        }

        return Inertia::render('auth/Welcome', ['name' => $request->user()->name]);
    }

    public function register(Request $request)
    {
        $user = $request->user();
        // usernames are case-insensitive: lowercase before the unique check
        $request->merge(['username' => $request->filled('username') ? strtolower(trim($request->input('username'))) : null]);
        $data = $request->validate([
            'name' => 'required|string|min:2|max:60',
            'username' => ['nullable', 'string', 'min:3', 'max:32', 'regex:/^[a-z0-9_.]+$/i', Rule::unique('users')->ignore($user->id)],
            'level' => 'nullable|in:beginner,mid,pro',
        ]);
        $user->forceFill([...$data, 'username' => isset($data['username']) ? strtolower($data['username']) : null, 'registered_at' => now(), 'locale' => app()->getLocale()])->save();

        return redirect()->intended(route('home'))->with('success', __('auth.welcome_done'));
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
