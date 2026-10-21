<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ---------- Bookball scheduler (run `php artisan schedule:work` locally, cron `schedule:run` in production) ----------
Illuminate\Support\Facades\Schedule::call(function () {
    $jobs = app(App\Domain\Booking\Actions\ExpireAndComplete::class);
    $jobs->expirePending();
    $jobs->completeEnded();
})->everyMinute()->name('bookings:expire-and-complete')->withoutOverlapping();

// games and chats: cancel games that did not reach 30% in 48 h, mark booked games as played,
// make quiet game chats read-only after 7 days and delete them 30 days later
Illuminate\Support\Facades\Schedule::call(function () {
    $games = app(App\Domain\Games\Games::class);
    $games->expireForming();
    $games->markPlayed();
})->everyFiveMinutes()->name('games:expire-and-played')->withoutOverlapping();

Illuminate\Support\Facades\Schedule::call(function () {
    $chat = app(App\Domain\Chat\Chat::class);
    $chat->archiveQuiet();
    $chat->deleteExpired();
})->hourly()->name('chats:archive-and-delete')->withoutOverlapping();
