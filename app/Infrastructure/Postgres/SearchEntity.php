<?php

declare(strict_types=1);

namespace App\Infrastructure\Postgres;

/**
 * The whitelist of what a search can filter and sort on. The SQL text only ever contains column names from
 * here; values are always bindings.
 */
final readonly class SearchEntity
{
    /** @param array<string, SearchField> $fields */
    public function __construct(
        public string $table,
        public array $fields,
    ) {}

    /** @return list<string> */
    public function names(): array
    {
        $names = array_keys($this->fields);
        sort($names);

        return $names;
    }
}
