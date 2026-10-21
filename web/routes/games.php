<?php

use App\Http\Controllers\Player\GameController;
use Illuminate\Support\Facades\Route;

// games pages of the player web (see README): open games, my games, create, game page and its actions
Route::get('games', [GameController::class, 'index'])->name('games.index');
Route::middleware('auth')->group(function () {
    Route::get('games/new', [GameController::class, 'create'])->name('games.create');
    Route::post('games', [GameController::class, 'store'])->middleware('throttle:20,10')->name('games.store');
    Route::prefix('games/{game}')->whereNumber('game')->middleware('throttle:60,1')->group(function () {
        Route::post('respond', [GameController::class, 'respond'])->name('games.respond');
        Route::post('join', [GameController::class, 'join'])->name('games.join');
        Route::post('leave', [GameController::class, 'leave'])->name('games.leave');
        Route::post('remove/{user}', [GameController::class, 'remove'])->whereNumber('user')->name('games.remove');
        Route::post('invite', [GameController::class, 'invite'])->name('games.invite');
        Route::post('cancel', [GameController::class, 'cancel'])->name('games.cancel');
        Route::post('book', [GameController::class, 'book'])->middleware('throttle:20,10')->name('games.book');
        Route::post('play-again', [GameController::class, 'playAgain'])->name('games.playAgain');
    });
});
Route::get('games/{game}', [GameController::class, 'show'])->whereNumber('game')->name('games.show');
