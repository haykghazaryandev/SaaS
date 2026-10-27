<?php

use App\Domain\Booking\Actions\ExpireAndComplete;
use App\Domain\Booking\AfterBooking\Reminders;
use App\Domain\Booking\Alerts\SlotAlerts;
use App\Domain\Chat\Chat;
use App\Domain\Club\Tournaments;
use App\Domain\Games\Games;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ---------- Bookball scheduler (run `php artisan schedule:work` locally, cron `schedule:run` in production) ----------
Schedule::call(function () {
    $jobs = app(ExpireAndComplete::class);
    $jobs->expirePending();
    $jobs->completeEnded();
})->everyMinute()->name('bookings:expire-and-complete')->withoutOverlapping();

// after booking: reminders 24 h and 1 h before, "team not here?" to the place, "how was the field?" after the game
Schedule::call(fn () => app(Reminders::class)->run())
    ->everyMinute()->name('bookings:reminders')->withoutOverlapping();

// games and chats: cancel games that did not reach 30% in 48 h, mark booked games as played,
// make quiet game chats read-only after 7 days and delete them 30 days later
Schedule::call(function () {
    $games = app(Games::class);
    $games->expireForming();
    $games->markPlayed();
})->everyFiveMinutes()->name('games:expire-and-played')->withoutOverlapping();

Schedule::call(function () {
    $chat = app(Chat::class);
    $chat->archiveQuiet();
    $chat->deleteExpired();
})->hourly()->name('chats:archive-and-delete')->withoutOverlapping();

// old "notify me if it frees up" alerts
Schedule::call(fn () => app(SlotAlerts::class)->prune())->daily()->name('alerts:prune');

// tournaments starting tomorrow: remind the accepted players who haven't paid their fee
Schedule::call(fn () => app(Tournaments::class)->remindTomorrow())->dailyAt('12:00')->timezone('Asia/Yerevan')->name('tournaments:remind-unpaid');
