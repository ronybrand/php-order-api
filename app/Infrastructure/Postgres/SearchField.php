<?php

declare(strict_types=1);

namespace App\Infrastructure\Postgres;

/**
 * One searchable column. Values are coerced to the column's type before they reach SQL, so a wrong type is a
 * VALIDATION-05 for the client instead of a driver error.
 */
final readonly class SearchField
{
    /** @param list<string> $allowed the accepted values of an enum column */
    public function __construct(
        public string $column,
        public FieldType $type,
        public array $allowed = [],
    ) {}
}
