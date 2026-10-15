<?php

declare(strict_types=1);

namespace App\Infrastructure\Postgres;

/** A compiled search: the WHERE and ORDER BY fragments plus their positional bindings. */
final readonly class SearchQuery
{
    /** @param list<mixed> $bindings */
    public function __construct(
        public string $where,
        public string $orderBy,
        public array $bindings,
    ) {}
}
