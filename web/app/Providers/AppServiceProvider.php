<?php

namespace App\Providers;

use App\Domain\Auth\CodeSender;
use App\Domain\Auth\LogCodeSender;
use App\Domain\Auth\TelegramGatewaySender;
use App\Domain\Booking\Alerts\SlotAlerts;
use App\Domain\Booking\Events\BookingChanged;
use Carbon\CarbonImmutable;
use App\Domain\Games\Games;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
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

        // /u/{player}: a username, or the id for players without one.
        // Here and not in routes/social.php: route files are not loaded when routes are cached (production).
        Route::bind('player', function (string $value) {
            $user = User::where('username', mb_strtolower($value))->first()
                ?? (ctype_digit($value) ? User::find((int) $value) : null);

            return $user ?? abort(404);
        });

        // behind Railway's proxy PHP sees plain http: an https APP_URL means every generated URL is https
        // (Filament assets, favicon, redirects), otherwise the browser blocks them as mixed content
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // at the field: running late, no-shows and their penalty, the booker's team hears the news
        Event::listen(BookingChanged::class, [\App\Domain\Booking\AfterBooking\AfterBookingListener::class, 'handle']);

        // a game follows its booking: accepted → booked, declined/expired → open again (with chat lines)
        Event::listen(BookingChanged::class, fn (BookingChanged $e) => app(Games::class)->bookingChanged($e->booking, $e->type));

        // "Notify me if it frees up": a booking let go of its time → tell the players waiting for it;
        // a player booked the time → their alert is done
        Event::listen(BookingChanged::class, function (BookingChanged $e) {
            $alerts = app(SlotAlerts::class);
            if (in_array($e->type, ['cancelled', 'declined', 'expired'], true)) {
                $alerts->whenFreed($e->booking);
            } elseif ($e->type === 'changed' && (isset($e->changes['starts_at']) || isset($e->changes['field_id']) || isset($e->changes['ends_at']))) {
                $old = isset($e->changes['starts_at'][0]) ? CarbonImmutable::parse($e->changes['starts_at'][0]) : null;
                $alerts->whenFreed($e->booking, $e->changes['field_id'][0] ?? null, $old);
            } elseif ($e->type === 'requested') {
                $alerts->booked($e->booking);
            }
        });
    }
}
