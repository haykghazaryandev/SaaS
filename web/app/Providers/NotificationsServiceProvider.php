<?php

namespace App\Providers;

use App\Domain\Booking\Events\BookingChanged;
use App\Domain\Notifications\Listeners\SendBookingTelegram;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Telegram notifications for booking changes. */
class NotificationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(BookingChanged::class, SendBookingTelegram::class);
    }
}
