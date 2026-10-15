<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Error\ApiException;
use App\Domain\Error\ErrorCode;
use App\Domain\Error\ErrorKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every failure in the one error envelope of the API: {message, code, params, request_id}. An ApiException
 * is shown as is; anything else is an unexpected failure that is logged in full while the client only sees
 * INTERNAL-00, never the underlying message.
 */
final readonly class ErrorRenderer
{
    public function __construct(private LoggerInterface $logger) {}

    public function render(Throwable $error, Request $request): JsonResponse
    {
        $requestId = RequestContext::requestId($request);
        /** @var array<string, string> $headers */
        $headers = [];

        if ($error instanceof NotFoundHttpException) {
            $error = ApiException::routeNotFound();
        } elseif ($error instanceof MethodNotAllowedHttpException) {
            $allow = $error->getHeaders()['Allow'] ?? null;
            $headers = is_string($allow) ? ['Allow' => $allow] : [];
            $error = ApiException::methodNotAllowed();
        } elseif ($error instanceof HttpExceptionInterface && $error->getStatusCode() === 413) {
            $error = ApiException::tooLarge(0);
        }

        if ($error instanceof ApiException) {
            return $this->response($error->kind->httpStatus(), $error->errorCode, $error->getMessage(), $error->params, $requestId, $headers + $this->headersFor($error));
        }

        $this->logger->error('unexpected error', ['exception' => $error, 'request_id' => $requestId]);

        return $this->response(500, ErrorCode::Internal, 'Internal error', [], $requestId, $headers);
    }

    /** @return array<string, string> */
    private function headersFor(ApiException $error): array
    {
        return match (true) {
            $error->kind === ErrorKind::Unauthenticated => ['WWW-Authenticate' => 'Bearer'],
            $error->retryAfter !== null => ['Retry-After' => (string) $error->retryAfter],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, string> $headers
     */
    private function response(int $status, ErrorCode $code, string $message, array $params, string $requestId, array $headers): JsonResponse
    {
        return new JsonResponse([
            'message' => $message,
            'code' => $code->value,
            // an empty list would be encoded as [] instead of {}
            'params' => (object) $params,
            'request_id' => $requestId,
        ], $status, $headers);
    }
}
