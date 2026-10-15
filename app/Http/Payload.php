<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Error\ApiException;
use Illuminate\Http\Request;
use JsonException;
use stdClass;

/**
 * Strict reading of a JSON request body: exactly one JSON object, only the expected fields, only the expected
 * types. Failures are VALIDATION-01 and never echo the offending content.
 */
final class Payload
{
    private const int MAX_DEPTH = 16;

    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values) {}

    /** @param list<string> $allowed */
    public static function fromRequest(Request $request, array $allowed): self
    {
        return self::fromJson($request->getContent(), $allowed);
    }

    /** @param list<string> $allowed */
    public static function fromJson(string $json, array $allowed): self
    {
        try {
            $decoded = json_decode($json, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ApiException::invalidField('body', 'request body is not valid JSON');
        }
        if (!$decoded instanceof stdClass) {
            throw ApiException::invalidField('body', 'request body must be a JSON object');
        }

        return self::fromObject($decoded, $allowed);
    }

    /** @param list<string> $allowed */
    public static function fromObject(stdClass $object, array $allowed, string $path = ''): self
    {
        $values = [];
        foreach (get_object_vars($object) as $key => $value) {
            $values[(string) $key] = $value;
        }
        foreach (array_keys($values) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw ApiException::invalidField($path . 'body', 'request body has an unexpected field');
            }
        }

        return new self($values);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function string(string $key): string
    {
        $value = $this->values[$key] ?? null;
        if (!is_string($value)) {
            throw ApiException::invalidField($key, "{$key} must be a string");
        }

        return $value;
    }

    public function optionalString(string $key): ?string
    {
        $value = $this->values[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw ApiException::invalidField($key, "{$key} must be a string");
        }

        return $value;
    }

    public function int(string $key, ?int $default = null): int
    {
        if (!$this->has($key) && $default !== null) {
            return $default;
        }
        $value = $this->values[$key] ?? null;
        if (!is_int($value)) {
            throw ApiException::invalidField($key, "{$key} must be an integer");
        }

        return $value;
    }

    /** A JSON number or a numeric string, kept as text so the decimal arithmetic stays exact. */
    public function decimal(string $key): string
    {
        $value = $this->values[$key] ?? null;
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            // The shortest representation that round-trips: 0.10 arrives as "0.1", never as 0.1000000000000000055.
            return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
        }
        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/D', $value) === 1) {
            return $value;
        }

        throw ApiException::invalidField($key, "{$key} must be a number");
    }

    /** @return list<mixed> */
    public function list(string $key): array
    {
        $value = $this->values[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw ApiException::invalidField($key, "{$key} must be a list");
        }

        return $value;
    }

    public function object(string $key): ?stdClass
    {
        $value = $this->values[$key] ?? null;
        if ($value !== null && !$value instanceof stdClass) {
            throw ApiException::invalidField($key, "{$key} must be an object");
        }

        return $value;
    }

    public function raw(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }
}
