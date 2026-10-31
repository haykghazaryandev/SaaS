<?php

use App\Models\PushSubscription;

// Web Push for the installed app (laravel-notification-channels/webpush). No keys = push is off (PushNotifications::enabled).
// Keys: php artisan webpush:vapid --show (see .env.example).
return [
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', env('APP_URL')),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => null,
    ],
    'model' => PushSubscription::class,
    'table_name' => 'push_subscriptions',
    'database_connection' => null,   // the default connection
    'client_options' => [],
    'automatic_padding' => true,
];
