<?php

use App\Http\Controllers\Player\ChatController;
use Illuminate\Support\Facades\Route;

// chat pages of the player web (see README): the chat list, a chat screen and its JSON actions
Route::middleware('auth')->prefix('messages')->name('messages.')->group(function () {
    Route::get('/', [ChatController::class, 'index'])->name('index');
    Route::post('direct/{user}', [ChatController::class, 'direct'])->whereNumber('user')->middleware('throttle:30,1')->name('direct');
    Route::post('place/{venue:slug}', [ChatController::class, 'venue'])->middleware('throttle:30,1')->name('venue');
    Route::patch('message/{message}', [ChatController::class, 'update'])->whereNumber('message')->middleware('throttle:60,1')->name('update');
    Route::delete('message/{message}', [ChatController::class, 'destroy'])->whereNumber('message')->middleware('throttle:60,1')->name('destroy');
    Route::prefix('{conversation}')->whereNumber('conversation')->group(function () {
        Route::get('/', [ChatController::class, 'show'])->name('show');
        Route::get('history', [ChatController::class, 'history'])->middleware('throttle:120,1')->name('history');
        Route::post('/', [ChatController::class, 'send'])->name('send');            // Chat::send has its own limit (20 per minute)
        Route::get('presence', [ChatController::class, 'presence'])->middleware('throttle:30,1')->name('presence');   // "online" in the header, every minute
        Route::post('read', [ChatController::class, 'read'])->middleware('throttle:120,1')->name('read');
    });
});
