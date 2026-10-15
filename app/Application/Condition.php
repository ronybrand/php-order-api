<?php

declare(strict_types=1);

namespace App\Application;

/** One predicate on a field, e.g. {"op": "gte", "value": 10}. An empty op means "eq". */
final readonly class Condition
{
    public function __construct(
        public string $op = 'eq',
        public mixed $value = null,
    ) {}
}
