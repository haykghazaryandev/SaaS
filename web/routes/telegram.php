<?php

use App\Http\Controllers\Telegram\TelegramController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

// "Connect Telegram" (deep link to the bot) and the bot webhook
Route::middleware('auth')->group(function () {
    Route::post('telegram/link', [TelegramController::class, 'link'])->name('telegram.link');
    Route::get('telegram/status', [TelegramController::class, 'status'])->name('telegram.status');
});

Route::post('telegram/webhook/{secret}', [TelegramController::class, 'webhook'])
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->middleware('throttle:120,1')
    ->name('telegram.webhook');
