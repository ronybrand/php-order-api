<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Order;
use DateTimeImmutable;

/** Persists orders together with their items. */
interface OrderRepository
{
    public function get(string $id): Order;

    /** Inserts the order and its items and gives them their database-generated ids. */
    public function create(Order $order): void;

    /**
     * Writes the status and the items of an order read earlier. It checks and increments the version: a concurrent
     * change fails with CONFLICT-00 instead of being overwritten silently.
     */
    public function save(Order $order): void;

    public function softDelete(string $id, string $actor, DateTimeImmutable $at): void;

    /** Whether the customer has any non soft-deleted order. */
    public function hasActive(string $customerId): bool;

    /** @return Page<Order> */
    public function search(SearchRequest $request): Page;
}
