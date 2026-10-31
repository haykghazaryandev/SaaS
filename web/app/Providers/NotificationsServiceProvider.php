<?php

namespace App\Providers;

use App\Domain\Booking\Events\BookingChanged;
use App\Domain\Notifications\Listeners\SendBookingTelegram;
use App\Domain\Notifications\PushNotifications;
use App\Domain\Notifications\WebPushChannel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\ReportHandler;
use NotificationChannels\WebPush\ReportHandlerInterface;

/** Telegram and Web Push notifications for booking changes; the Web Push client. */
class NotificationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(BookingChanged::class, SendBookingTelegram::class);

        // our channel (one device at a time) with our client: see WebPushChannel, PushNotifications::client()
        $this->app->when(WebPushChannel::class)->needs(WebPush::class)->give(fn () => PushNotifications::client());
        $this->app->when(WebPushChannel::class)->needs(ReportHandlerInterface::class)->give(ReportHandler::class);
    }
}
