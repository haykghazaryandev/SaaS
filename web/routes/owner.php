<?php

use App\Domain\Venues\Menus;
use App\Http\Controllers\Owner\BookingController;
use App\Http\Controllers\Owner\Club\CardController;
use App\Http\Controllers\Owner\Club\CheckoutController;
use App\Http\Controllers\Owner\Club\MoneyController;
use App\Http\Controllers\Owner\Club\NowController;
use App\Http\Controllers\Owner\Club\PlayerController;
use App\Http\Controllers\Owner\Club\ShopController;
use App\Http\Controllers\Owner\Club\TournamentController;
use App\Http\Controllers\Owner\OwnerChatController;
use App\Http\Controllers\Owner\ScheduleController;
use App\Http\Controllers\Owner\SettingsController;
use App\Http\Controllers\Owner\TeamController;
use App\Http\Controllers\Owner\TodayController;
use App\Http\Controllers\Owner\VenueSwitchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ---------- owner web (/owner): only owners and staff of a place ----------
// EnsureVenueStaff sends guests to /login?to=owner and keeps the current place in the session. Staff: schedule and bookings; owner: also settings.
Route::prefix('owner')->name('owner.')->middleware('venue.staff')->group(function () {
    Route::get('/', TodayController::class)->name('today');
    Route::get('requests', [TodayController::class, 'requests'])->name('requests')->middleware('venue.menu:requests');
    // local / staging: test requests again (they expire after 2 h unanswered)
    Route::post('requests/demo', [TodayController::class, 'demoRequests'])->name('requests.demo')->middleware('venue.menu:requests');
    // chats in the panel (the JSON of a chat stays under /messages)
    Route::get('messages', [OwnerChatController::class, 'index'])->name('messages')->middleware('venue.menu:messages');
    Route::get('messages/{conversation}', [OwnerChatController::class, 'show'])->whereNumber('conversation')->name('messages.show')->middleware('venue.menu:messages');
    // where an owner lands (login, the app's home): a club at its desk ("Now"), a field at Today
    Route::get('start', fn (Request $request) => redirect()->route(
        Menus::start($request->attributes->get('venue'), $request->attributes->get('venueRole'))
    ))->name('start');
    Route::post('venue', VenueSwitchController::class)->name('venue');
    Route::get('schedule', ScheduleController::class)->name('schedule')->middleware('venue.menu:schedule');

    Route::get('bookings/quote', [BookingController::class, 'quote'])->name('bookings.quote');
    Route::post('bookings', [BookingController::class, 'store'])->name('bookings.store');
    Route::get('bookings/{booking}', [BookingController::class, 'show'])->whereNumber('booking')->name('bookings.show');
    Route::patch('bookings/{booking}', [BookingController::class, 'update'])->whereNumber('booking')->name('bookings.update')->middleware('venue.owner:schedule,edit');
    Route::post('bookings/{booking}/accept', [BookingController::class, 'accept'])->name('bookings.accept')->middleware('venue.menu:requests');
    Route::post('bookings/{booking}/decline', [BookingController::class, 'decline'])->name('bookings.decline')->middleware('venue.menu:requests');
    Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
    Route::post('bookings/{booking}/no-show', [BookingController::class, 'noShow'])->name('bookings.no-show');
    Route::post('bookings/{booking}/arrived', [BookingController::class, 'arrived'])->name('bookings.arrived');

    // ---------- club: the bill of a booking (items, card / cash), players, cards (money only after a scan), shop, tournaments, money ----------
    Route::get('bookings/{booking}/checkout', [CheckoutController::class, 'show'])->whereNumber('booking')->name('bookings.checkout');
    Route::post('bookings/{booking}/items', [CheckoutController::class, 'addItem'])->whereNumber('booking')->name('bookings.items.store');
    Route::delete('booking-items/{item}', [CheckoutController::class, 'removeItem'])->whereNumber('item')->name('bookings.items.destroy');
    Route::post('bookings/{booking}/scan', [CheckoutController::class, 'scan'])->whereNumber('booking')->name('bookings.scan');
    Route::get('bookings/{booking}/cards', [CheckoutController::class, 'cards'])->whereNumber('booking')->name('bookings.cards');
    Route::post('bookings/{booking}/pay', [CheckoutController::class, 'pay'])->whereNumber('booking')->name('bookings.pay');
    Route::post('bookings/{booking}/stop', [CheckoutController::class, 'stop'])->whereNumber('booking')->name('bookings.stop');
    // the desk: every table now (the booked games playing, their check: time + items = what to pay)
    Route::get('now', [NowController::class, 'index'])->name('now')->middleware('venue.menu:now');
    // local / staging: test games on the tables right now (the club:demo-now command)
    Route::post('now/demo', [NowController::class, 'demo'])->name('now.demo')->middleware('venue.menu:now');
    Route::post('bookings/{booking}/start', [NowController::class, 'start'])->whereNumber('booking')->name('bookings.start')->middleware('venue.menu:now');
    Route::post('bookings/{booking}/extend', [NowController::class, 'extend'])->whereNumber('booking')->name('bookings.extend')->middleware('venue.menu:now');
    Route::post('fields/{field}/walk-in', [NowController::class, 'walkIn'])->whereNumber('field')->name('fields.walkin')->middleware('venue.menu:now');
    Route::get('players', [PlayerController::class, 'index'])->name('players')->middleware('venue.menu:players');
    Route::get('players/{user}', [PlayerController::class, 'show'])->whereNumber('user')->name('players.show')->middleware('venue.menu:players');
    Route::post('players/{user}/rating', [PlayerController::class, 'rating'])->whereNumber('user')->name('players.rating')->middleware('venue.menu:players');
    Route::post('players/{user}/chat', [PlayerController::class, 'chat'])->whereNumber('user')->name('players.chat')->middleware('venue.menu:players');
    Route::get('cards', [CardController::class, 'index'])->name('cards')->middleware('venue.menu:cards');
    Route::post('cards', [CardController::class, 'store'])->name('cards.store')->middleware('venue.menu:cards');
    Route::get('cards/{code}', [CardController::class, 'show'])->where('code', '[a-z0-9]{20}')->name('cards.show')->middleware('venue.menu:cards');
    Route::prefix('cards/{code}')->where(['code' => '[a-z0-9]{20}'])->name('cards.')->middleware('venue.menu:cards')->group(function () {
        Route::post('topup', [CardController::class, 'topUp'])->name('topup');
    });
    Route::get('shop', [ShopController::class, 'index'])->name('shop')->middleware('venue.menu:shop');
    Route::post('shop', [ShopController::class, 'store'])->name('shop.store')->middleware('venue.owner:shop,edit');
    Route::post('shop/{product}', [ShopController::class, 'update'])->name('shop.update')->middleware('venue.owner:shop,edit');
    Route::post('shop/{product}/sell', [ShopController::class, 'sell'])->name('shop.sell')->middleware('venue.menu:shop');
    Route::get('tournaments', [TournamentController::class, 'index'])->name('tournaments')->middleware('venue.menu:tournaments');
    Route::get('tournaments/new', [TournamentController::class, 'create'])->name('tournaments.create')->middleware('venue.owner:tournaments,create');
    Route::post('tournaments', [TournamentController::class, 'store'])->name('tournaments.store')->middleware('venue.owner:tournaments,create');
    Route::get('tournaments/{tournament}', [TournamentController::class, 'show'])->whereNumber('tournament')->name('tournaments.show')->middleware('venue.menu:tournaments');
    Route::put('tournaments/{tournament}', [TournamentController::class, 'update'])->name('tournaments.update')->middleware('venue.owner:tournaments,create');
    Route::patch('tournament-entries/{entry}', [TournamentController::class, 'entry'])->name('tournaments.entry')->middleware('venue.menu:tournaments');
    Route::delete('tournament-entries/{entry}', [TournamentController::class, 'remove'])->name('tournaments.remove')->middleware('venue.menu:tournaments');
    Route::post('tournaments/{tournament}/draw', [TournamentController::class, 'draw'])->name('tournaments.draw')->middleware('venue.menu:tournaments');
    Route::post('tournaments/{tournament}/cancel', [TournamentController::class, 'cancel'])->name('tournaments.cancel')->middleware('venue.owner:tournaments,create');
    Route::post('tournaments/{tournament}/announce', [TournamentController::class, 'announce'])->middleware('throttle:10,1')->name('tournaments.announce')->middleware('venue.menu:tournaments');
    Route::post('tournaments/{tournament}/chat', [TournamentController::class, 'chat'])->name('tournaments.chat')->middleware('venue.menu:tournaments');
    Route::post('tournaments/{tournament}/remind', [TournamentController::class, 'remind'])->middleware('throttle:6,1')->name('tournaments.remind')->middleware('venue.menu:tournaments');
    Route::patch('tournament-matches/{match}', [TournamentController::class, 'match'])->name('tournaments.match')->middleware('venue.menu:tournaments');
    Route::get('money', MoneyController::class)->name('money')->middleware('venue.owner:money,today');

    // ---------- the owner only: the place's details, prices, hours, courts, closures, the team, ZoneSpot's fee ----------
    Route::middleware('venue.owner')->group(function () {
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
        Route::get('team', [TeamController::class, 'index'])->name('team');
        Route::post('team', [TeamController::class, 'store'])->name('team.store');
        Route::delete('team/{user}', [TeamController::class, 'destroy'])->whereNumber('user')->name('team.destroy');
        Route::put('settings/menus', [SettingsController::class, 'menus'])->name('settings.menus');
        Route::get('fee', [MoneyController::class, 'fee'])->name('fee');
    });
});
