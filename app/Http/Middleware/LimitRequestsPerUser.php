<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Config\Settings;
use App\Http\RequestContext;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-user limits, applied after authentication: one budget for every request and a tighter one for the writes
 * (POST/PUT/PATCH/DELETE), so a burst of writes cannot starve the user's reads or the other way round.
 */
final readonly class LimitRequestsPerUser
{
    public function __construct(private RateLimiter $limiter, private Settings $settings) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $subject = RequestContext::actor($request)->id ?? 'anonymous';
        RateLimits::consume($this->limiter, "user:{$subject}", $this->settings->rateLimitPerUser);
        if (!$request->isMethodSafe()) {
            RateLimits::consume($this->limiter, "write:{$subject}", $this->settings->rateLimitWrites);
        }

        return $next($request);
    }
}
