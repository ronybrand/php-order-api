<?php

declare(strict_types=1);

namespace App\Domain\Error;

/** Groups errors by the HTTP status family they map to. */
enum ErrorKind
{
    case Validation;
    case NotFound;
    case Conflict;
    case Unauthenticated;
    case Forbidden;
    case MethodNotAllowed;
    case TooLarge;
    case RateLimited;

    public function httpStatus(): int
    {
        return match ($this) {
            self::Validation => 400,
            self::NotFound => 404,
            self::Conflict => 409,
            self::Unauthenticated => 401,
            self::Forbidden => 403,
            self::MethodNotAllowed => 405,
            self::TooLarge => 413,
            self::RateLimited => 429,
        };
    }
}
