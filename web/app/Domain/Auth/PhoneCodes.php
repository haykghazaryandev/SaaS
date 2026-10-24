<?php

namespace App\Domain\Auth;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Login with a phone number: send a 4-digit code, then check it.
 * Limits: 3 codes per phone per 10 min, 10 per IP per hour, 5 tries per code, 5 minutes to use it.
 */
class PhoneCodes
{
    public function __construct(private CodeSender $sender) {}

    public function send(string $phone, string $ip, string $purpose = 'login'): void
    {
        foreach (['otp-phone:'.$phone => [3, 600], 'otp-ip:'.$ip => [10, 3600]] as $key => [$max, $decay]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['phone' => __('auth.code.too_many', ['min' => ceil(RateLimiter::availableIn($key) / 60)])]);
            }
            RateLimiter::hit($key, $decay);
        }
        $code = (string) random_int(1000, 9999);
        try {
            $requestId = $this->sender->send($phone, $code);
        } catch (CodeSendFailed $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }
        OtpCode::where('phone', $phone)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        OtpCode::create([
            'phone' => $phone, 'code_hash' => Hash::make($code), 'purpose' => $purpose, 'gateway_request_id' => $requestId,
            'expires_at' => now()->addMinutes(5), 'ip' => $ip,
        ]);
    }

    /** Checks the code; returns the user (created on first login, not registered yet). */
    public function verify(string $phone, string $code): User
    {
        $otp = OtpCode::where('phone', $phone)->whereNull('consumed_at')->where('expires_at', '>', now())->latest('id')->first();
        if (! $otp) {
            throw ValidationException::withMessages(['code' => __('auth.code.expired')]);
        }
        if ($otp->attempts >= 5) {
            $otp->update(['consumed_at' => now()]);
            throw ValidationException::withMessages(['code' => __('auth.code.too_many_tries')]);
        }
        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            throw ValidationException::withMessages(['code' => __('auth.code.wrong', ['n' => 5 - $otp->attempts])]);
        }
        $otp->update(['consumed_at' => now()]);
        $user = User::withTrashed()->firstOrCreate(['phone' => $phone], ['locale' => app()->getLocale()]);
        if ($user->trashed()) {
            $user->restore();
        }
        $user->forceFill(['phone_verified_at' => $user->phone_verified_at ?? now(), 'last_seen_at' => now()])->save();
        $user->syncAdmin();
        app(\App\Domain\Booking\AfterBooking\BookingTeam::class)->claimInvites($user);

        return $user;
    }
}
