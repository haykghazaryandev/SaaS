<?php

use App\Http\Controllers\Owner\BookingController;
use App\Http\Controllers\Owner\Club\CardController;
use App\Http\Controllers\Owner\Club\MoneyController;
use App\Http\Controllers\Owner\Club\ShopController;
use App\Http\Controllers\Owner\Club\TournamentController;
use App\Http\Controllers\Owner\Club\TrainingController;
use App\Http\Controllers\Owner\ScheduleController;
use App\Http\Controllers\Owner\SettingsController;
use App\Http\Controllers\Owner\TodayController;
use App\Http\Controllers\Owner\VenueSwitchController;
use Illuminate\Support\Facades\Route;

// ---------- owner web (/owner): only owners and staff of a place ----------
// EnsureVenueStaff sends guests to /login?to=owner and keeps the current place in the session. Staff: schedule and bookings; owner: also settings.
Route::prefix('owner')->name('owner.')->middleware('venue.staff')->group(function () {
    Route::get('/', TodayController::class)->name('today');
    Route::post('venue', VenueSwitchController::class)->name('venue');
    Route::get('schedule', ScheduleController::class)->name('schedule');

    Route::get('bookings/quote', [BookingController::class, 'quote'])->name('bookings.quote');
    Route::post('bookings', [BookingController::class, 'store'])->name('bookings.store');
    Route::get('bookings/{booking}', [BookingController::class, 'show'])->whereNumber('booking')->name('bookings.show');
    Route::patch('bookings/{booking}', [BookingController::class, 'update'])->whereNumber('booking')->name('bookings.update');
    Route::post('bookings/{booking}/accept', [BookingController::class, 'accept'])->name('bookings.accept');
    Route::post('bookings/{booking}/decline', [BookingController::class, 'decline'])->name('bookings.decline');
    Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
    Route::post('bookings/{booking}/no-show', [BookingController::class, 'noShow'])->name('bookings.no-show');
    Route::post('bookings/{booking}/arrived', [BookingController::class, 'arrived'])->name('bookings.arrived');

    Route::post('bookings/{booking}/pay', [CardController::class, 'payBooking'])->name('bookings.pay');

    // ---------- club: player cards (QR), trainings, shop, tournaments, money (each only where the feature is on) ----------
    Route::get('players', [CardController::class, 'index'])->name('players');
    Route::post('cards', [CardController::class, 'store'])->name('cards.store');
    Route::get('cards/{code}', [CardController::class, 'show'])->where('code', '[a-z0-9]{20}')->name('cards.show');
    Route::prefix('cards/{code}')->where(['code' => '[a-z0-9]{20}'])->name('cards.')->group(function () {
        Route::post('topup', [CardController::class, 'topUp'])->name('topup');
        Route::post('charge', [CardController::class, 'charge'])->name('charge');
        Route::post('adjust', [CardController::class, 'adjust'])->name('adjust');
        Route::post('discount', [CardController::class, 'discount'])->name('discount');
        Route::post('rating', [CardController::class, 'rating'])->name('rating');
        Route::post('visit/{training}', [CardController::class, 'visit'])->name('visit');
        Route::post('sell/{product}', [CardController::class, 'sell'])->name('sell');
    });
    Route::get('trainings', [TrainingController::class, 'index'])->name('trainings');
    Route::post('trainings', [TrainingController::class, 'store'])->name('trainings.store');
    Route::put('trainings/{training}', [TrainingController::class, 'update'])->name('trainings.update');
    Route::get('shop', [ShopController::class, 'index'])->name('shop');
    Route::post('shop', [ShopController::class, 'store'])->name('shop.store');
    Route::post('shop/{product}', [ShopController::class, 'update'])->name('shop.update');
    Route::post('shop/{product}/sell', [ShopController::class, 'sell'])->name('shop.sell');
    Route::get('tournaments', [TournamentController::class, 'index'])->name('tournaments');
    Route::post('tournaments', [TournamentController::class, 'store'])->name('tournaments.store');
    Route::put('tournaments/{tournament}', [TournamentController::class, 'update'])->name('tournaments.update');
    Route::patch('tournament-entries/{entry}', [TournamentController::class, 'entry'])->name('tournaments.entry');
    Route::get('money', MoneyController::class)->name('money');

    Route::get('settings', [SettingsController::class, 'show'])->name('settings');
    Route::put('settings/prices', [SettingsController::class, 'prices'])->name('settings.prices');
    Route::put('settings/hours', [SettingsController::class, 'hours'])->name('settings.hours');
    Route::put('settings/rules', [SettingsController::class, 'rules'])->name('settings.rules');
    Route::put('settings', [SettingsController::class, 'save'])->name('settings.save');
    Route::post('courts', [SettingsController::class, 'storeCourt'])->name('courts.store');
    Route::patch('courts/{field}', [SettingsController::class, 'updateCourt'])->whereNumber('field')->name('courts.update');
    Route::delete('courts/{field}', [SettingsController::class, 'destroyCourt'])->whereNumber('field')->name('courts.destroy');
    Route::post('closures', [SettingsController::class, 'storeClosure'])->name('closures.store');
    Route::delete('closures/{closure}', [SettingsController::class, 'destroyClosure'])->name('closures.destroy');
});
