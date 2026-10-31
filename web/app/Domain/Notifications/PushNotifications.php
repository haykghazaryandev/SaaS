<?php

namespace App\Domain\Notifications;

use App\Models\PushSubscription;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushMessage;
use Psr\Log\NullLogger;

/**
 * Web Push to the installed app (and desktop browsers): "Notifications on this device" in Profile → Notifications.
 * The same people, types and switches (User::notify_prefs) as Telegram, without needing Telegram.
 * Off while VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY are not set: nothing is sent and the switch is hidden.
 * Sending is our WebPushChannel (one device at a time; subscriptions the push service answers 404 / 410 for are deleted).
 */
class PushNotifications
{
    public static function enabled(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }

    /** For the browser's pushManager.subscribe (null = push is off). */
    public static function publicKey(): ?string
    {
        return self::enabled() ? (string) config('webpush.vapid.public_key') : null;
    }

    /** The library's requirement check (in its constructor) has been logged in this process. */
    private static bool $checked = false;

    /**
     * The library's client, read from config when a push is sent. With a logger: without one the library raises
     * "install GMP or BCMath" as a PHP notice, which Laravel turns into an exception (no bcmath on some dev machines).
     * The check runs on every client: logged once per process, then a null logger (production has bcmath).
     */
    public static function client(): WebPush
    {
        $logger = self::$checked ? new NullLogger : Log::channel();
        self::$checked = true;
        $auth = self::enabled() ? ['VAPID' => [
            'subject' => (string) (config('webpush.vapid.subject') ?: config('app.url')),
            'publicKey' => (string) config('webpush.vapid.public_key'),
            'privateKey' => (string) config('webpush.vapid.private_key'),
        ]] : [];

        return (new WebPush($auth, [], new Client(['timeout' => 30, ...config('webpush.client_options', [])]), new HttpFactory, new HttpFactory, null, $logger))
            ->setReuseVAPIDHeaders(true)
            ->setAutomaticPadding(config('webpush.automatic_padding', true));
    }

    /** Push is on, the person has a device subscribed and wants this category (the same switch as Telegram). */
    public static function reaches(object $notifiable, string $category): bool
    {
        return self::enabled() && $notifiable instanceof User
            && $notifiable->wantsTelegram($category)
            && $notifiable->pushSubscriptions()->exists();
    }

    /** One row per device: a browser someone else used before (no logout) now belongs to this person. */
    public function subscribe(User $user, string $endpoint, string $publicKey, string $authToken, ?string $encoding = null, ?string $userAgent = null): PushSubscription
    {
        return PushSubscription::updateOrCreate(['endpoint' => $endpoint], [
            'user_id' => $user->id, 'public_key' => $publicKey, 'auth_token' => $authToken,
            'content_encoding' => $encoding ?: 'aes128gcm', 'user_agent' => $userAgent ? Str::limit($userAgent, 500, '') : null,
        ]);
    }

    public function unsubscribe(User $user, string $endpoint): void
    {
        PushSubscription::where('user_id', $user->id)->where('endpoint', $endpoint)->delete();
    }

    /**
     * The browser replaced its subscription (pushsubscriptionchange in sw.js, no session there):
     * knowing the old endpoint (a long secret URL) is the proof. False when the old one is unknown.
     */
    public function renew(string $oldEndpoint, string $endpoint, string $publicKey, string $authToken, ?string $encoding = null): bool
    {
        $old = PushSubscription::where('endpoint', $oldEndpoint)->first();
        if (! $old) {
            return false;
        }
        if ($endpoint !== $oldEndpoint) {
            PushSubscription::where('endpoint', $endpoint)->where('id', '!=', $old->id)->delete();
        }
        $old->forceFill(['endpoint' => $endpoint, 'public_key' => $publicKey, 'auth_token' => $authToken, 'content_encoding' => $encoding ?: 'aes128gcm'])->save();

        return true;
    }

    /**
     * The payload sw.js shows. $text may be Telegram HTML (tags are dropped, &amp; etc. decoded);
     * $tag: a newer notification with the same tag replaces the older one (e.g. one per chat).
     */
    public static function message(string $text, ?string $link, string $tag, ?string $title = null): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($title !== null ? self::plain($title) : __('brand'))
            ->body(Str::limit(self::plain($text), 300))
            ->icon('/icons/icon-192.png')
            ->tag($tag)
            ->renotify()
            ->data(['url' => self::url($link)])
            // a day: later than that the news is stale
            ->options(['TTL' => 86400, 'urgency' => 'high']);
    }

    public static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /** The page to open, a path on this site (sw.js resolves it against its own origin and opens nothing else). */
    public static function url(?string $link): string
    {
        return '/'.ltrim($link ?? '', '/');
    }
}
