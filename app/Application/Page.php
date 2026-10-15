<?php

declare(strict_types=1);

namespace App\Application;

/**
 * One page of results plus the total number of matches.
 *
 * @template T
 */
final readonly class Page
{
    /** @param list<T> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $size,
    ) {}
}
