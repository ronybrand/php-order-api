<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Config\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Symfony\Component\HttpFoundation\Response;

/** Allows exactly the configured origins; any other origin simply gets no CORS headers. */
final readonly class AllowConfiguredOrigins
{
    public function __construct(private Settings $settings) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        if (!is_string($origin) || !in_array($origin, $this->settings->corsOrigins, true)) {
            return $next($request);
        }

        $isPreflight = $request->isMethod('OPTIONS') && $request->headers->has('Access-Control-Request-Method');
        $response = $isPreflight ? new IlluminateResponse('', 204) : $next($request);
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Vary', 'Origin', false);
        $response->headers->set('Access-Control-Expose-Headers', 'X-Request-Id, Retry-After');
        if ($isPreflight) {
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Request-Id');
            $response->headers->set('Access-Control-Max-Age', '600');
        }

        return $response;
    }
}
