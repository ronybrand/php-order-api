<?php

declare(strict_types=1);

namespace App\Domain;

use Brick\Math\BigDecimal;

/** The data a client supplies for a new item. Unvalidated. */
final readonly class ItemInput
{
    public function __construct(
        public string $description,
        public BigDecimal $unitPrice,
        public int $quantity,
    ) {}
}
