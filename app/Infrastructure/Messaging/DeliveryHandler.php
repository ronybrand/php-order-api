<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies the Processor's decision to a delivery. The original is acknowledged only after the broker confirmed
 * the copy (retry or dead letter); if the copy is not confirmed the original goes back to the queue, so a
 * message is never lost between "removed from here" and "stored there". It moves bytes and never decodes the
 * event, so customer data does not pass through its logs.
 */
final readonly class DeliveryHandler
{
    /** @param Closure(int): void $sleep waits between a failure and its retry (injected so tests do not wait) */
    public function __construct(
        private MessageMover $mover,
        private LoggerInterface $logger,
        private Closure $sleep,
        private int $retryDelaySeconds = Processor::RETRY_DELAY_SECONDS,
    ) {}

    public function handle(Action $action, Message $message, Delivery $delivery): void
    {
        try {
            match ($action) {
                Action::Ack => null,
                Action::Retry => $this->retry($message),
                Action::DeadLetter => $this->mover->move(Processor::DEAD_LETTER_QUEUE, $message->body, $message->id, $message->retries),
            };
        } catch (Throwable $e) {
            $this->logger->error('could not move message, requeueing', ['action' => $action->name, 'exception' => $e::class]);
            $delivery->requeue();

            return;
        }
        $delivery->ack();
    }

    private function retry(Message $message): void
    {
        // A fixed backoff: the consumer holds one unacknowledged message at a time, so this really throttles the queue.
        ($this->sleep)($this->retryDelaySeconds);
        $this->mover->move(Processor::QUEUE, $message->body, $message->id, $message->retries + 1);
    }
}
