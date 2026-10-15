<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Domain\OrderStatusChanged;

/** Delivers the notification of one event. A PermanentFailure means retrying cannot help. */
interface Notifier
{
    public function notify(OrderStatusChanged $event): void;
}
