<?php

declare(strict_types=1);

namespace App\Infrastructure\Postgres;

use App\Domain\Error\ApiException;
use App\Domain\Error\ErrorCode;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Converts the database errors the client should understand into ApiException; everything else is returned
 * untouched (the HTTP layer reports those as INTERNAL-00 without their text).
 */
final class PostgresErrors
{
    private const string TAX_ID_INDEX = 'customers_tax_id_active_uq';
    private const string PASSPORT_INDEX = 'customers_passport_active_uq';

    public static function translate(Throwable $error): Throwable
    {
        $pdo = $error instanceof QueryException ? $error->getPrevious() : $error;
        if (!$pdo instanceof PDOException) {
            return $error;
        }
        $info = $pdo->errorInfo;
        $sqlState = is_array($info) && is_string($info[0] ?? null) ? $info[0] : (string) $pdo->getCode();

        return match ($sqlState) {
            '23505' => self::uniqueViolation($pdo->getMessage()),
            '23503', '23514', '22001' => self::integrity(),
            '40001', '40P01' => self::concurrentModification(),
            default => $error,
        };
    }

    /**
     * Runs a statement and translates the database errors the client should understand.
     *
     * @template T
     *
     * @param callable(): T $statement
     * @return T
     */
    public static function guard(callable $statement): mixed
    {
        try {
            return $statement();
        } catch (QueryException $e) {
            throw self::translate($e);
        }
    }

    public static function concurrentModification(): ApiException
    {
        return ApiException::conflict(ErrorCode::ConcurrentModification, 'the resource was modified by someone else, reload it and try again');
    }

    private static function uniqueViolation(string $message): ApiException
    {
        return match (true) {
            str_contains($message, self::TAX_ID_INDEX) => ApiException::conflict(ErrorCode::DuplicateTaxId, 'a customer with this tax_id already exists'),
            str_contains($message, self::PASSPORT_INDEX) => ApiException::conflict(ErrorCode::DuplicatePassport, 'a customer with this passport_number already exists'),
            default => self::integrity(),
        };
    }

    private static function integrity(): ApiException
    {
        return ApiException::conflict(ErrorCode::DataIntegrity, 'the operation violates a data integrity rule');
    }
}
