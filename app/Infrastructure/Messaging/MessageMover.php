<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

/** Copies a message to a queue and returns only once the broker confirmed it. */
interface MessageMover
{
    /** @throws \Throwable when the broker did not confirm the copy */
    public function move(string $queue, string $body, string $messageId, int $retries): void;
}
