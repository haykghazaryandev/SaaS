<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'telegram' => [
        'gateway_token' => env('TELEGRAM_GATEWAY_TOKEN'),
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME', 'BookballBot'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        // the Mini App's short name from BotFather /newapp (t.me/<bot>/<app> links); empty = not registered
        'app_name' => env('TELEGRAM_APP_NAME'),
    ],

    // "Continue with Google" (Google Cloud → OAuth client, type Web); empty = the button is hidden.
    // Authorised redirect URI: <APP_URL>/login/google/callback
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/login/google/callback'),
    ],

    // how login codes are delivered: log (development) | gateway (Telegram Gateway API)
    'otp_driver' => env('OTP_DRIVER', 'log'),

];
