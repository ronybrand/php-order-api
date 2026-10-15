<?php

declare(strict_types=1);

namespace App\Domain\Error;

use RuntimeException;

/**
 * A failure the client is allowed to see: stable code, readable message, optional parameters. Params never
 * carry PII (tax id, e-mail...): they exist for interpolation, not for debugging. Anything that is not an
 * ApiException is an unexpected failure, reported as INTERNAL-00 without its message.
 */
final class ApiException extends RuntimeException
{
    /** @param array<string, mixed> $params */
    private function __construct(
        public readonly ErrorKind $kind,
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly array $params = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $params */
    public static function validation(ErrorCode $code, string $message, array $params = []): self
    {
        return new self(ErrorKind::Validation, $code, $message, $params);
    }

    /** The common VALIDATION-01 for a single offending field. */
    public static function invalidField(string $field, string $message): self
    {
        return self::validation(ErrorCode::InvalidRequest, $message, ['field' => $field]);
    }

    /** @param array<string, mixed> $params */
    public static function notFound(ErrorCode $code, string $message, array $params = []): self
    {
        return new self(ErrorKind::NotFound, $code, $message, $params);
    }

    /** @param array<string, mixed> $params */
    public static function conflict(ErrorCode $code, string $message, array $params = []): self
    {
        return new self(ErrorKind::Conflict, $code, $message, $params);
    }

    public static function unauthenticated(): self
    {
        return new self(ErrorKind::Unauthenticated, ErrorCode::Unauthenticated, 'Authentication required');
    }

    public static function forbidden(): self
    {
        return new self(ErrorKind::Forbidden, ErrorCode::Forbidden, 'Access denied');
    }

    public static function methodNotAllowed(): self
    {
        return new self(ErrorKind::MethodNotAllowed, ErrorCode::MethodNotAllowed, 'Method not allowed');
    }

    public static function routeNotFound(): self
    {
        return new self(ErrorKind::NotFound, ErrorCode::RouteNotFound, 'Route not found');
    }

    public static function tooLarge(int $maxBytes): self
    {
        return new self(ErrorKind::TooLarge, ErrorCode::BodyTooLarge, 'Request body too large', ['max_bytes' => $maxBytes]);
    }

    public static function rateLimited(): self
    {
        return new self(ErrorKind::RateLimited, ErrorCode::RateLimited, 'Rate limit exceeded');
    }
}
