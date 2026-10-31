<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClubPublicController;
use App\Http\Controllers\LangController;
use App\Http\Controllers\Player\BookingController;
use App\Http\Controllers\Player\BookingTeamController;
use App\Http\Controllers\Player\CardsController;
use App\Http\Controllers\Player\HomeController;
use App\Http\Controllers\Player\MyBookingsController;
use App\Http\Controllers\Player\MyTournamentsController;
use App\Http\Controllers\Player\PlaceController;
use App\Http\Controllers\Player\RateController;
use App\Http\Controllers\RoleSwitchController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

// interface texts for the browser (resources/js/lib/i18n.ts), versioned in the URL; see LangController
Route::get('lang/{locale}.json', LangController::class)->where('locale', '[a-z]{2}')->name('lang');

// ---------- player web ----------
Route::get('/', HomeController::class)->name('home');
Route::get('places/{venue:slug}', [PlaceController::class, 'show'])->name('places.show');
Route::get('places/{venue:slug}/slots', [PlaceController::class, 'slots'])->name('places.slots');
// booking requests: logged in, or a guest with name + phone + code (the code step logs the guest in)
Route::post('bookings', [BookingController::class, 'store'])->middleware('throttle:30,10')->name('bookings.store');
Route::middleware('auth')->group(function () {
    Route::get('me/bookings', MyBookingsController::class)->name('me.bookings');
    Route::get('me/tournaments', MyTournamentsController::class)->name('me.tournaments');
    Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
    // after booking: the team, "running late", "how was the field?"
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('bookings/{booking}/team', [BookingTeamController::class, 'friends'])->name('bookings.team');
        Route::post('bookings/{booking}/team/respond', [BookingTeamController::class, 'respond'])->name('bookings.team.respond');
        Route::delete('bookings/{booking}/team/{player}', [BookingTeamController::class, 'destroy'])->name('bookings.team.destroy');
        Route::post('bookings/{booking}/leave', [BookingTeamController::class, 'leave'])->name('bookings.leave');
        Route::post('bookings/{booking}/late', [BookingTeamController::class, 'late'])->name('bookings.late');
        Route::post('rate/{booking}', [RateController::class, 'store'])->name('rate.store');
    });
    Route::get('join/{token}', [BookingTeamController::class, 'join'])->name('bookings.join');
    Route::get('rate/{booking}', [RateController::class, 'show'])->name('rate.show');
    // club cards: one per club, its own QR
    Route::get('me/cards', [CardsController::class, 'index'])->name('me.cards');
    Route::post('places/{venue:slug}/card', [CardsController::class, 'store'])->middleware('throttle:20,1')->name('places.card');
    // later: games, messages, friends, profile
});

// ---------- club links: a scanned card QR, shop photos, tournament registration (Instagram bio) ----------
Route::get('c/{code}', [ClubPublicController::class, 'card'])->where('code', '[a-z0-9]{20}')->name('card.open');
Route::get('shop-photo/{id}-{token}.webp', [ClubPublicController::class, 'photo'])->whereNumber('id')->where('token', '[A-Za-z0-9]{8}')->name('shop.photo');
Route::get('t/{code}', [ClubPublicController::class, 'tournament'])->where('code', '[a-z0-9]{8}')->name('tournament.show');
// registering needs an account (log in with Telegram, then back to the tournament)
Route::post('t/{code}', [ClubPublicController::class, 'register'])->where('code', '[a-z0-9]{8}')->middleware(['auth', 'throttle:10,1'])->name('tournament.register');

// ---------- login / registration (Telegram, email / phone code, username + password, Google) ----------
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
    // email + password (and "Forgot password?"), and "Continue with Google" (both add the phone on the welcome page)
    Route::post('login/password', [AuthController::class, 'passwordLogin'])->middleware('throttle:30,1')->name('login.password');
    Route::post('login/register', [AuthController::class, 'passwordRegister'])->middleware('throttle:10,1')->name('login.register');
    Route::post('login/register/verify', [AuthController::class, 'passwordRegisterVerify'])->middleware('throttle:30,1')->name('login.register.verify');
    Route::post('login/register/resend', [AuthController::class, 'passwordRegisterResend'])->middleware('throttle:10,1')->name('login.register.resend');
    Route::post('login/forgot', [AuthController::class, 'passwordForgot'])->middleware('throttle:5,1')->name('login.forgot');
    Route::get('login/reset/{token}', [AuthController::class, 'passwordResetForm'])->name('login.reset');
    Route::post('login/reset', [AuthController::class, 'passwordReset'])->middleware('throttle:10,1')->name('login.reset.store');
    Route::get('login/google', [AuthController::class, 'googleRedirect'])->middleware('throttle:30,1')->name('login.google');
    Route::get('login/google/callback', [AuthController::class, 'googleCallback'])->middleware('throttle:30,1')->name('login.google.callback');
});
// Telegram Mini App: log in with Telegram's signed initData (works logged in or not)
Route::post('tg/app/login', [AuthController::class, 'telegramApp'])->middleware('throttle:30,1')->name('login.telegram.app');

// links in our Telegram messages that open the site logged in (signed, single-use)
Route::get('l/{user}', [AuthController::class, 'loginLink'])->middleware('throttle:30,1')->name('login.link');
Route::middleware('auth')->group(function () {
    Route::get('welcome', [AuthController::class, 'welcome'])->name('welcome');
    Route::post('welcome', [AuthController::class, 'register'])->name('welcome.store');
    // booking needs a phone confirmed with Telegram (accounts from email / Google)
    Route::get('confirm-phone', [AuthController::class, 'confirmPhone'])->name('phone.confirm');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    // admin / owner / owner as staff / player app
    Route::match(['get', 'post'], 'switch', RoleSwitchController::class)->name('switch');
});

// unknown addresses get the 404 in the visitor's language (FriendlyErrors); any method, and no CSRF check, so a
// POST is a 404 too (not 405 / 419)
Route::any('{fallbackPlaceholder}', fn () => abort(404))->where('fallbackPlaceholder', '.*')->fallback()
    ->withoutMiddleware(ValidateCsrfToken::class);
