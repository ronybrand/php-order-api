<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an incoming X-Request-Id only when it is a canonical UUID: anything else (log injection, absurdly long
 * values) is replaced by a fresh one.
 */
final class AssignRequestId
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get('X-Request-Id');
        $id = is_string($incoming) && Uuid::isValid($incoming) && strlen($incoming) === 36
            ? strtolower($incoming)
            : Uuid::uuid4()->toString();
        RequestContext::setRequestId($request, $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
