<?php

namespace App\Domain\Social;

use RuntimeException;

/** A friends / games / chat rule was broken; text: __('social.error.<reason>'). */
class SocialException extends RuntimeException
{
    public function __construct(public readonly string $reason, array $params = [])
    {
        parent::__construct(__('social.error.'.$reason, $params));
    }
}
