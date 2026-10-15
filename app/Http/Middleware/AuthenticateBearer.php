<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Actor;
use App\Auth\InvalidTokenException;
use App\Auth\JwtVerifier;
use App\Domain\Error\ApiException;
use App\Http\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Requires a valid bearer token and puts the caller on the request as an Actor. */
final readonly class AuthenticateBearer
{
    public function __construct(private JwtVerifier $verifier) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->headers->get('Authorization');
        if (!is_string($header) || preg_match('/^Bearer\s+(\S+)$/Di', $header, $matches) !== 1) {
            throw ApiException::unauthenticated();
        }
        try {
            $claims = $this->verifier->verify($matches[1]);
        } catch (InvalidTokenException) {
            throw ApiException::unauthenticated();
        }
        RequestContext::setActor($request, new Actor($claims->subject, $claims->roles));

        return $next($request);
    }
}
