<?php

use App\Http\Controllers\Owner\BookingController;
use App\Http\Controllers\Owner\Club\CardController;
use App\Http\Controllers\Owner\Club\CheckoutController;
use App\Http\Controllers\Owner\Club\MoneyController;
use App\Http\Controllers\Owner\Club\NowController;
use App\Http\Controllers\Owner\Club\PlayerController;
use App\Http\Controllers\Owner\Club\ShopController;
use App\Http\Controllers\Owner\Club\TournamentController;
use App\Http\Controllers\Owner\ScheduleController;
use App\Http\Controllers\Owner\SettingsController;
use App\Http\Controllers\Owner\TodayController;
use App\Http\Controllers\Owner\VenueSwitchController;
use Illuminate\Support\Facades\Route;

// ---------- owner web (/owner): only owners and staff of a place ----------
// EnsureVenueStaff sends guests to /login?to=owner and keeps the current place in the session. Staff: schedule and bookings; owner: also settings.
Route::prefix('owner')->name('owner.')->middleware('venue.staff')->group(function () {
    Route::get('/', TodayController::class)->name('today');
    // where an owner lands (login, the app's home): a club at its desk ("Now"), a field at Today
    Route::get('start', fn (\Illuminate\Http\Request $request) => redirect()->route(
        collect(['wallet', 'shop'])->contains(fn ($f) => $request->attributes->get('venue')->hasFeature($f)) ? 'owner.now' : 'owner.today'
    ))->name('start');
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

    // ---------- club: the bill of a booking (items, card / cash), players, cards (money only after a scan), shop, tournaments, money ----------
    Route::get('bookings/{booking}/checkout', [CheckoutController::class, 'show'])->whereNumber('booking')->name('bookings.checkout');
    Route::post('bookings/{booking}/items', [CheckoutController::class, 'addItem'])->whereNumber('booking')->name('bookings.items.store');
    Route::delete('booking-items/{item}', [CheckoutController::class, 'removeItem'])->whereNumber('item')->name('bookings.items.destroy');
    Route::post('bookings/{booking}/scan', [CheckoutController::class, 'scan'])->whereNumber('booking')->name('bookings.scan');
    Route::post('bookings/{booking}/pay', [CheckoutController::class, 'pay'])->whereNumber('booking')->name('bookings.pay');
    Route::post('bookings/{booking}/stop', [CheckoutController::class, 'stop'])->whereNumber('booking')->name('bookings.stop');
    // the desk: every table now (running games with their check, free tables with "Start now")
    Route::get('now', [NowController::class, 'index'])->name('now');
    Route::post('now/start', [NowController::class, 'start'])->name('now.start');
    Route::get('players', [PlayerController::class, 'index'])->name('players');
    Route::get('players/{user}', [PlayerController::class, 'show'])->whereNumber('user')->name('players.show');
    Route::post('players/{user}/rating', [PlayerController::class, 'rating'])->whereNumber('user')->name('players.rating');
    Route::get('cards', [CardController::class, 'index'])->name('cards');
    Route::post('cards', [CardController::class, 'store'])->name('cards.store');
    Route::get('cards/{code}', [CardController::class, 'show'])->where('code', '[a-z0-9]{20}')->name('cards.show');
    Route::prefix('cards/{code}')->where(['code' => '[a-z0-9]{20}'])->name('cards.')->group(function () {
        Route::post('topup', [CardController::class, 'topUp'])->name('topup');
        Route::post('discount', [CardController::class, 'discount'])->name('discount');
    });
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
