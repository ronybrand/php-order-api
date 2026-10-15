<?php

declare(strict_types=1);

namespace App\Infrastructure\Postgres;

use DateTimeImmutable;
use DateTimeZone;
use stdClass;

/** Typed access to the columns of a fetched row: strict, so a schema drift fails loudly instead of casting. */
final class Rows
{
    public static function string(stdClass $row, string $column): string
    {
        $value = $row->{$column};
        \assert(is_string($value));

        return $value;
    }

    public static function nullableString(stdClass $row, string $column): ?string
    {
        $value = $row->{$column};
        \assert($value === null || is_string($value));

        return $value;
    }

    public static function int(stdClass $row, string $column): int
    {
        $value = $row->{$column};
        \assert(is_int($value) || (is_string($value) && is_numeric($value)));

        return (int) $value;
    }

    public static function time(stdClass $row, string $column): DateTimeImmutable
    {
        return (new DateTimeImmutable(self::string($row, $column)))->setTimezone(new DateTimeZone('UTC'));
    }

    public static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $value) === 1;
    }
}
