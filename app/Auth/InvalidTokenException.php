<?php

declare(strict_types=1);

namespace App\Auth;

use RuntimeException;

/** Every token that cannot be trusted, whatever the reason: neither the caller nor the client learns why. */
final class InvalidTokenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('invalid token');
    }
}
