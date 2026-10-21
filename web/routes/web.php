<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Player\BookingController;
use App\Http\Controllers\Player\HomeController;
use App\Http\Controllers\Player\MyBookingsController;
use App\Http\Controllers\Player\PlaceController;
use Illuminate\Support\Facades\Route;

// ---------- player web ----------
Route::get('/', HomeController::class)->name('home');
Route::get('places/{venue:slug}', [PlaceController::class, 'show'])->name('places.show');
Route::get('places/{venue:slug}/slots', [PlaceController::class, 'slots'])->name('places.slots');
// booking requests: logged in, or a guest with name + phone + code (the code step logs the guest in)
Route::post('bookings/code', [BookingController::class, 'code'])->middleware('throttle:20,10')->name('bookings.code');
Route::post('bookings', [BookingController::class, 'store'])->middleware('throttle:30,10')->name('bookings.store');
Route::middleware('auth')->group(function () {
    Route::get('me/bookings', MyBookingsController::class)->name('me.bookings');
    Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
    // later: games, messages, friends, profile
});

// ---------- login / registration (phone + Telegram code) ----------
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'show'])->name('login');
    Route::post('login/code', [AuthController::class, 'sendCode'])->name('login.code');
    Route::post('login/verify', [AuthController::class, 'verify'])->name('login.verify');
    Route::post('login/change', [AuthController::class, 'changePhone'])->name('login.change');
});
Route::middleware('auth')->group(function () {
    Route::get('welcome', [AuthController::class, 'welcome'])->name('welcome');
    Route::post('welcome', [AuthController::class, 'register'])->name('welcome.store');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
});
