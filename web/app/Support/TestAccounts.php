<?php

namespace App\Support;


/**
 * The seeded demo accounts can log in without Telegram on a developer's computer (never on staging or production):
 * phone + a code shown on screen. Players +374 93 000 100 … 159, owners +374 91 000 001 … 009.
 */
class TestAccounts
{
    /** Only on a developer's computer (and in the tests): staging and production log in with Telegram. */
    public static function enabled(): bool
    {
        return app()->environment(['local', 'testing']);
    }

    public static function is(?string $phone): bool
    {
        if (! self::enabled() || ! $phone) {
            return false;
        }
        // on a developer's own computer the admin too (never on staging: that is a real person's number)
        if (app()->isLocal() && \App\Models\User::where('phone', $phone)->where('is_admin', true)->exists()) {
            return true;
        }

        return (bool) preg_match('/^\+374(93000(1[0-5]\d)|9100000[1-9])$/', $phone);
    }
}
