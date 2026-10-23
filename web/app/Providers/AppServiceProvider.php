<?php

namespace App\Providers;

use App\Domain\Auth\CodeSender;
use App\Domain\Auth\LogCodeSender;
use App\Domain\Auth\TelegramGatewaySender;
use App\Domain\Booking\Events\BookingChanged;
use App\Domain\Games\Games;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // login codes: written to the log in development, sent by Telegram Gateway in production
        $this->app->bind(CodeSender::class, fn () => config('services.otp_driver') === 'gateway' ? new TelegramGatewaySender : new LogCodeSender);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        // a game follows its booking: accepted → booked, declined/expired → open again (with chat lines)
        Event::listen(BookingChanged::class, fn (BookingChanged $e) => app(Games::class)->bookingChanged($e->booking, $e->type));
    }
}
