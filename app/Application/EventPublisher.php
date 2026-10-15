<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\OrderStatusChanged;

/**
 * Announces order events. Publishing happens after the database commit and is best effort: see
 * docs/adr/0002 for why this is not a transactional outbox.
 */
interface EventPublisher
{
    public function publishStatusChanged(OrderStatusChanged $event): void;
}
