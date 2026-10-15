<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use PhpAmqpLib\Message\AMQPMessage;

final readonly class AmqpDelivery implements Delivery
{
    public function __construct(private AMQPMessage $message) {}

    public function ack(): void
    {
        $this->message->ack();
    }

    public function requeue(): void
    {
        $this->message->nack(true);
    }
}
