<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Config\Settings;
use App\Domain\Error\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses bodies above the limit. A declared Content-Length is checked up front; the body itself is then read
 * with a ceiling of limit + 1 bytes, so a chunked upload (no Content-Length) cannot skip the limit.
 */
final readonly class LimitBodySize
{
    public function __construct(private Settings $settings) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $max = $this->settings->maxBodyBytes;
        $declared = $request->headers->get('Content-Length');
        if (is_string($declared) && ctype_digit($declared) && (int) $declared > $max) {
            throw ApiException::tooLarge($max);
        }

        $stream = $request->getContent(true);
        $body = stream_get_contents($stream, $max + 1, 0);
        if ($body !== false && strlen($body) > $max) {
            throw ApiException::tooLarge($max);
        }

        return $next($request);
    }
}
