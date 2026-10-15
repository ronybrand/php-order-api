<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

/** What the consumer must do with a delivery. */
enum Action
{
    /** Done, or a duplicate: acknowledge. */
    case Ack;

    /** Transient failure: republish with the retry count incremented, after the retry delay. */
    case Retry;

    /** Unrecoverable: publish to the dead-letter queue. */
    case DeadLetter;
}
