<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

/** How a delivery is settled with the broker. */
interface Delivery
{
    public function ack(): void;

    /** Gives the message back to the broker, to be delivered again. */
    public function requeue(): void;
}
