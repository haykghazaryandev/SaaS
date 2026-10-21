<?php

use App\Http\Controllers\Social\FriendsController;
use App\Http\Controllers\Social\NotificationController;
use App\Http\Controllers\Social\ProfileController;
use App\Http\Controllers\Social\SafetyController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// social pages of the player web (see README): friends, profiles, blocking / reports, the notification bell

// /u/{player}: a username, or the id for players without one
Route::bind('player', function (string $value) {
    $user = User::where('username', mb_strtolower($value))->first()
        ?? (ctype_digit($value) ? User::find((int) $value) : null);

    return $user ?? abort(404);
});

Route::middleware('auth')->group(function () {
    Route::get('friends', [FriendsController::class, 'index'])->name('friends.index');
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('friends/{user}', [FriendsController::class, 'request'])->whereNumber('user')->name('friends.request');
        Route::post('friends/{user}/accept', [FriendsController::class, 'accept'])->whereNumber('user')->name('friends.accept');
        Route::delete('friends/{user}', [FriendsController::class, 'remove'])->whereNumber('user')->name('friends.remove');
        Route::post('users/{user}/block', [SafetyController::class, 'block'])->whereNumber('user')->name('users.block');
        Route::delete('users/{user}/block', [SafetyController::class, 'unblock'])->whereNumber('user')->name('users.unblock');
    });
    Route::post('reports', [SafetyController::class, 'report'])->middleware('throttle:10,10')->name('reports.store');

    Route::get('u/{player}', [ProfileController::class, 'show'])->name('users.show');
    Route::get('me/profile', [ProfileController::class, 'edit'])->name('me.profile');
    Route::patch('me/profile', [ProfileController::class, 'update'])->name('me.profile.update');

    Route::get('notifications', [NotificationController::class, 'index'])->middleware('throttle:120,1')->name('notifications.index');
    Route::post('notifications/read', [NotificationController::class, 'read'])->middleware('throttle:120,1')->name('notifications.read');
});
