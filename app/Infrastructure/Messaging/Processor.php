<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Domain\OrderStatusChanged;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides what happens to each message. It owns no connection, so the retry, dedupe and dead-letter rules are
 * tested without a broker. Delivery is at-least-once: a message already completed (same message_id) is
 * acknowledged without notifying again, and only a successful notification marks the id as done.
 */
final readonly class Processor
{
    public const string QUEUE = 'order.status.changed';
    public const string DEAD_LETTER_QUEUE = self::QUEUE . '.dlq';
    public const int MAX_RETRIES = 3;
    public const int RETRY_DELAY_SECONDS = 2;

    public function __construct(
        private Notifier $notifier,
        private SeenSet $seen,
        private LoggerInterface $logger,
    ) {}

    public function process(Message $message): Action
    {
        try {
            $event = $this->decode($message->body);
        } catch (InvalidArgumentException) {
            $this->logger->error('discarding malformed message', ['message_id' => $message->id]);

            return Action::DeadLetter;
        }
        if ($message->id !== '' && $this->seen->has($message->id)) {
            $this->logger->info('duplicate message ignored', ['message_id' => $message->id]);

            return Action::Ack;
        }

        try {
            $this->notifier->notify($event);
        } catch (PermanentFailure $e) {
            $this->logger->error('notification failed permanently', $this->context($event, $e));

            return Action::DeadLetter;
        } catch (Throwable $e) {
            if ($message->retries >= self::MAX_RETRIES) {
                $this->logger->error('notification failed, retries exhausted', $this->context($event, $e) + ['retries' => $message->retries]);

                return Action::DeadLetter;
            }
            $this->logger->warning('notification failed, will retry', $this->context($event, $e) + ['retries' => $message->retries]);

            return Action::Retry;
        }

        if ($message->id !== '') {
            $this->seen->add($message->id);
        }
        $this->logger->info('notification sent', ['order_id' => $event->orderId, 'message_id' => $message->id]);

        return Action::Ack;
    }

    private function decode(string $body): OrderStatusChanged
    {
        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('not JSON');
        }
        if (!is_array($data)) {
            throw new InvalidArgumentException('not an object');
        }

        return OrderStatusChanged::fromArray($data);
    }

    /**
     * The exception class and message may name the SMTP server; they never carry the customer's e-mail or name.
     *
     * @return array<string, string>
     */
    private function context(OrderStatusChanged $event, Throwable $error): array
    {
        return ['order_id' => $event->orderId, 'exception' => $error::class, 'error' => $error->getMessage()];
    }
}
