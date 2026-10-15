<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\EventPublisher;
use App\Domain\OrderStatusChanged;

/** Used when no broker is configured: the API works, it just does not notify. */
final class NullPublisher implements EventPublisher
{
    public function publishStatusChanged(OrderStatusChanged $event): void {}
}
