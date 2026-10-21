<?php

use App\Http\Controllers\Player\SlotAlertController;
use Illuminate\Support\Facades\Route;

// "Notify me if it frees up" (a booked time of a field)
Route::middleware('auth')->group(function () {
    Route::post('alerts', [SlotAlertController::class, 'store'])->middleware('throttle:30,1')->name('alerts.store');
    Route::delete('alerts/{alert}', [SlotAlertController::class, 'destroy'])->name('alerts.destroy');
});
