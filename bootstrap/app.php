<?php

declare(strict_types=1);

use App\Http\ErrorRenderer;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AllowConfiguredOrigins;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\LimitBodySize;
use App\Http\Middleware\LimitRequestsPerAddress;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // An explicit stack instead of the framework defaults: nothing here rewrites the request body (no string
        // trimming, no empty-string-to-null), and the order is deliberate: id first so every response and every
        // error carries it, then headers, CORS, the coarse per-address limit, and the body size limit.
        $middleware->use([
            AssignRequestId::class,
            AddSecurityHeaders::class,
            AllowConfiguredOrigins::class,
            LimitRequestsPerAddress::class,
            LimitBodySize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(static fn(): bool => true);
        $exceptions->render(static fn(Throwable $e, Request $request) => app(ErrorRenderer::class)->render($e, $request));
        // ApiException is an expected outcome, not an error to report; the renderer logs the unexpected ones.
        $exceptions->dontReport([App\Domain\Error\ApiException::class]);
    })->create();
