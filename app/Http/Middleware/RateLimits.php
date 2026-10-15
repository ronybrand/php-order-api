<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Error\ApiException;
use Illuminate\Cache\RateLimiter;

/** The one place that spends a token of a fixed-window budget (per minute) or refuses with RATE-00. */
final class RateLimits
{
    private const int WINDOW_SECONDS = 60;

    public static function consume(RateLimiter $limiter, string $key, int $perMinute): void
    {
        if ($limiter->tooManyAttempts($key, $perMinute)) {
            throw ApiException::rateLimited()->withRetryAfter(max(1, $limiter->availableIn($key)));
        }
        $limiter->hit($key, self::WINDOW_SECONDS);
    }
}
