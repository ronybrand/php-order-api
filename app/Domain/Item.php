<?php

declare(strict_types=1);

namespace App\Domain;

use Brick\Math\BigDecimal;

/** An order line. Its subtotal is derived, never stored. */
final class Item
{
    public function __construct(
        public private(set) ?string $id,
        public private(set) ?string $orderId,
        public readonly string $description,
        public readonly BigDecimal $unitPrice,
        public private(set) int $quantity,
    ) {}

    public function subtotal(): BigDecimal
    {
        return $this->unitPrice->multipliedBy($this->quantity);
    }

    public function changeQuantity(int $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function persisted(string $id, string $orderId): void
    {
        $this->id = $id;
        $this->orderId = $orderId;
    }
}
