<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Error\ApiException;
use App\Http\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Must run after AuthenticateBearer: with no actor on the request it denies. */
final class RequireRole
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if (RequestContext::actor($request)?->hasRole($role) !== true) {
            throw ApiException::forbidden();
        }

        return $next($request);
    }
}
