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
    // free "Log in with Telegram" (the bot confirms the phone) and the email backup
    Route::post('login/telegram', [AuthController::class, 'telegramStart'])->middleware('throttle:20,1')->name('login.telegram');
    Route::get('login/telegram/{token}', [AuthController::class, 'telegramStatus'])->middleware('throttle:120,1')->name('login.telegram.status');
    Route::post('login/telegram/{token}/complete', [AuthController::class, 'telegramComplete'])->middleware('throttle:20,1')->name('login.telegram.complete');
    Route::post('login/telegram/{token}/simulate', [AuthController::class, 'telegramSimulate'])->middleware('throttle:20,1')->name('login.telegram.simulate');
    Route::post('login/email', [AuthController::class, 'emailSend'])->name('login.email');
    Route::post('login/email/verify', [AuthController::class, 'emailVerify'])->name('login.email.verify');
});
Route::middleware('auth')->group(function () {
    Route::get('welcome', [AuthController::class, 'welcome'])->name('welcome');
    Route::post('welcome', [AuthController::class, 'register'])->name('welcome.store');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
});
