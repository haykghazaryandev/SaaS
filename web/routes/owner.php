<?php

use App\Http\Controllers\Owner\BookingController;
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

    Route::get('settings', [SettingsController::class, 'show'])->name('settings');
    Route::put('settings/prices', [SettingsController::class, 'prices'])->name('settings.prices');
    Route::put('settings/hours', [SettingsController::class, 'hours'])->name('settings.hours');
    Route::put('settings/rules', [SettingsController::class, 'rules'])->name('settings.rules');
    Route::put('settings', [SettingsController::class, 'save'])->name('settings.save');
    Route::post('closures', [SettingsController::class, 'storeClosure'])->name('closures.store');
    Route::delete('closures/{closure}', [SettingsController::class, 'destroyClosure'])->name('closures.destroy');
});
