<?php

namespace App\Http\Controllers\Social;

use App\Domain\Notifications\PushEndpoint;
use App\Domain\Notifications\PushNotifications;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Notifications on this device" (Web Push): the browser's subscription, saved per device. JSON for resources/js/lib/push.ts. */
class PushController extends Controller
{
    public function __construct(private PushNotifications $push) {}

    public function subscribe(Request $request): JsonResponse
    {
        abort_unless(PushNotifications::enabled(), 404);
        $data = $request->validate(self::rules());
        $this->push->subscribe($request->user(), $data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'],
            $data['contentEncoding'] ?? null, $request->userAgent());
        // logout removes this device's subscription (AuthController::logout)
        $request->session()->put('push_endpoint', $data['endpoint']);

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1024']]);
        $this->push->unsubscribe($request->user(), $data['endpoint']);
        $request->session()->forget('push_endpoint');

        return response()->json(['ok' => true]);
    }

    /** From the service worker (pushsubscriptionchange): no session and no CSRF token there, the old endpoint is the proof. */
    public function renew(Request $request): JsonResponse
    {
        abort_unless(PushNotifications::enabled(), 404);
        $data = $request->validate(['old_endpoint' => ['required', 'string', 'max:1024'], ...self::rules()]);
        $ok = $this->push->renew($data['old_endpoint'], $data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'], $data['contentEncoding'] ?? null);

        return response()->json(['ok' => $ok], $ok ? 200 : 404);
    }

    private static function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:1024', self::check(fn ($v) => PushEndpoint::valid($v))],
            'keys' => ['required', 'array'],
            // keys minishlink can't encrypt for would break pushes to this person: check the bytes, not only the letters
            'keys.p256dh' => ['required', 'string', 'max:255', self::check(fn ($v) => PushEndpoint::validKey($v))],
            'keys.auth' => ['required', 'string', 'max:255', self::check(fn ($v) => PushEndpoint::validSecret($v))],
            'contentEncoding' => ['nullable', 'in:aesgcm,aes128gcm'],
        ];
    }

    private static function check(callable $ok): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($ok) {
            if (! is_string($value) || ! $ok($value)) {
                $fail('validation.regex')->translate();
            }
        };
    }
}
