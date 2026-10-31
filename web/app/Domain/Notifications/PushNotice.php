<?php

namespace App\Domain\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/** A ready Web Push message for code that already runs in a queued job (ChatTelegramDigest): sent with notifyNow. */
class PushNotice extends Notification
{
    public function __construct(public WebPushMessage $message) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return $this->message;
    }
}
