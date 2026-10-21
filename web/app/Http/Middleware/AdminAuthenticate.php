<?php

namespace App\Http\Middleware;

use Filament\Http\Middleware\Authenticate;

/**
 * Admin panel guard. There is no Filament login form: guests go to our phone + code login (/login)
 * and come back to /admin after it. Logged-in users who are not admins get 403 (User::canAccessPanel).
 */
class AdminAuthenticate extends Authenticate
{
    protected function redirectTo($request): ?string
    {
        return route('login');
    }
}
