<?php

use App\Http\Controllers\Social\AvatarController;
use App\Http\Controllers\Social\EmailController;
use App\Http\Controllers\Social\FriendsController;
use App\Http\Controllers\Social\NotificationController;
use App\Http\Controllers\Social\ProfileController;
use App\Http\Controllers\Social\SafetyController;
use Illuminate\Support\Facades\Route;

// social pages of the player web (see README): friends, profiles, blocking / reports, the notification bell

// /u/{player}: a username or an id, resolved in AppServiceProvider (bindings in route files are lost with route:cache)

// profile photos (stored in the database, see AvatarPhoto)
Route::get('avatars/{file}', [AvatarController::class, 'show'])->where('file', '[0-9]+-[A-Za-z0-9]+\.webp')->name('avatars.show');

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
    Route::patch('me/notifications', [ProfileController::class, 'notifications'])->middleware('throttle:60,1')->name('me.notifications');

    Route::get('notifications', [NotificationController::class, 'index'])->middleware('throttle:120,1')->name('notifications.index');
    Route::post('notifications/read', [NotificationController::class, 'read'])->middleware('throttle:120,1')->name('notifications.read');
});

// profile photo
Route::middleware('auth')->group(function () {
    Route::post('me/avatar', [AvatarController::class, 'store'])->middleware('throttle:10,1')->name('me.avatar');
    Route::delete('me/avatar', [AvatarController::class, 'destroy'])->name('me.avatar.destroy');
});

// the account's email ("Your email"): a code goes to the new address; it is saved when the code is right
Route::middleware('auth')->group(function () {
    Route::get('me/email', [EmailController::class, 'show'])->name('me.email');
    Route::post('me/email', [EmailController::class, 'change'])->middleware('throttle:10,10')->name('me.email.change');
    Route::post('me/email/send', [EmailController::class, 'send'])->middleware('throttle:10,10')->name('me.email.send');
    Route::post('me/email/verify', [EmailController::class, 'verify'])->middleware('throttle:30,10')->name('me.email.verify');
});
