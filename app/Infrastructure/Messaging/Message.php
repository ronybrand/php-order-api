<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

/** A delivery as the processor sees it, independent of the AMQP library. */
final readonly class Message
{
    public function __construct(
        public string $id,
        public string $body,
        /** Value of the x-retry-count header. */
        public int $retries = 0,
    ) {}
}
