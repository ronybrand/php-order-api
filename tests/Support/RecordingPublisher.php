<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\EventPublisher;
use App\Domain\OrderStatusChanged;
use RuntimeException;

final class RecordingPublisher implements EventPublisher
{
    /** @var list<OrderStatusChanged> */
    public array $events = [];

    public ?RuntimeException $failure = null;

    public function publishStatusChanged(OrderStatusChanged $event): void
    {
        $this->events[] = $event;
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
