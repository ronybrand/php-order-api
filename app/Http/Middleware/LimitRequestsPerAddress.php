<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Config\Settings;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A coarse limit per client address, applied before authentication so anonymous floods are cut early. */
final readonly class LimitRequestsPerAddress
{
    public function __construct(private RateLimiter $limiter, private Settings $settings) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('health')) {
            return $next($request);
        }
        RateLimits::consume($this->limiter, 'ip:' . ($request->ip() ?? 'unknown'), $this->settings->rateLimitPerIp);

        return $next($request);
    }
}
