<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NotificationChannels\WebPush\PushSubscription as BasePushSubscription;

/**
 * A browser / phone that gets Web Push (PushNotifications). The package's channel reads endpoint + keys and
 * deletes the row when the push service answers 404 / 410 (the subscription is gone).
 */
class PushSubscription extends BasePushSubscription
{
    protected $table = 'push_subscriptions';

    protected $fillable = ['user_id', 'endpoint', 'public_key', 'auth_token', 'content_encoding', 'user_agent'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
