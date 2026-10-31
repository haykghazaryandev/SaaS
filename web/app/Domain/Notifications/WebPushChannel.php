<?php

namespace App\Domain\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\ContentEncoding;
use Minishlink\WebPush\Subscription;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\WebPushChannel as BaseWebPushChannel;
use NotificationChannels\WebPush\WebPushMessageInterface;
use Throwable;

/**
 * The package's channel, one device at a time: the package encrypts all of a person's devices in one go, so one
 * broken subscription would throw for all of them and the job would retry forever.
 * - A row whose own data is unusable (endpoint, keys, encoding: the checks of PushEndpoint, as at subscribe) is deleted.
 * - Any other error (VAPID / openssl / client trouble on our side) is reported and that device skipped, the row kept:
 *   a server problem must not wipe everyone's subscriptions.
 * 404 / 410 from the push service: deleted by the package's ReportHandler, as before.
 */
class WebPushChannel extends BaseWebPushChannel
{
    public function send(mixed $notifiable, Notification $notification): array
    {
        if (! is_object($notifiable) || ! method_exists($notifiable, 'routeNotificationFor') || ! method_exists($notification, 'toWebPush')) {
            return [];
        }
        $subscriptions = $notifiable->routeNotificationFor('WebPush', $notification);
        if ($subscriptions->isEmpty()) {
            return [];
        }
        /** @var WebPushMessageInterface $message */
        $message = $notification->toWebPush($notifiable, $notification);
        $payload = json_encode($message->toArray(), JSON_THROW_ON_ERROR);

        $reports = [];
        foreach ($subscriptions as $s) {
            if (! self::usable($s)) {
                Log::warning('web push: unusable subscription deleted', ['subscription' => $s->id]);
                $s->delete();

                continue;
            }
            try {
                $this->webPush->queueNotification(new Subscription($s->endpoint, $s->public_key, $s->auth_token,
                    $s->content_encoding ?? ContentEncoding::aes128gcm), $payload, $message->getOptions());
                foreach ($this->webPush->flush() as $report) {   // network errors come back as failed reports, not exceptions
                    $this->reportHandler->handleReport($report, $s, $message);
                    $reports[] = $report;
                }
            } catch (Throwable $e) {
                report($e);
                Log::warning('web push failed, subscription kept', ['subscription' => $s->id, 'error' => $e->getMessage()]);
            }
        }

        return $reports;
    }

    /** The row's own data is what the library needs (the same checks as PushController at subscribe). */
    private static function usable(PushSubscription $s): bool
    {
        $encoding = $s->getRawOriginal('content_encoding') ?? 'aes128gcm';   // raw: the enum cast throws on a bad value

        return is_string($s->endpoint) && PushEndpoint::valid($s->endpoint)
            && is_string($s->public_key) && PushEndpoint::validKey($s->public_key)
            && is_string($s->auth_token) && PushEndpoint::validSecret($s->auth_token)
            && in_array($encoding, ['aesgcm', 'aes128gcm'], true);
    }
}
