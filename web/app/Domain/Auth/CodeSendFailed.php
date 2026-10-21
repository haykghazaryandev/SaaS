<?php

namespace App\Domain\Auth;

use RuntimeException;

class CodeSendFailed extends RuntimeException
{
    /** $reason: no_telegram | provider_error */
    public function __construct(public readonly string $reason)
    {
        parent::__construct(__('auth.code.'.$reason));
    }
}
