<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use Closure;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Psr\Log\LoggerInterface;

/** Reads the queue and applies the Processor's decision to each delivery. */
final class AmqpConsumer implements MessageMover
{
    private const int POLL_SECONDS = 2;
    private const int CONFIRM_TIMEOUT_SECONDS = 5;
    private const string RETRY_HEADER = 'x-retry-count';

    private ?AMQPChannel $channel = null;

    public function __construct(
        private readonly string $url,
        private readonly Processor $processor,
        private readonly LoggerInterface $logger,
        private readonly int $retryDelaySeconds = Processor::RETRY_DELAY_SECONDS,
    ) {}

    /**
     * Consumes until $shouldStop returns true. The caller reconnects after a broker failure (this throws).
     *
     * @param Closure(): bool $shouldStop
     */
    public function consume(Closure $shouldStop): void
    {
        $connection = AmqpConnection::open($this->url);
        $this->channel = $channel = $connection->channel();
        try {
            AmqpConnection::declareTopology($channel);
            // Moving a message (retry, dead letter) must be confirmed by the broker before the original is acked.
            $channel->confirm_select();
            // One unacknowledged message at a time: the fixed retry delay then really throttles the queue.
            $channel->basic_qos(0, 1, false);
            $handler = new DeliveryHandler($this, $this->logger, static function (int $seconds): void {
                if ($seconds > 0) {
                    sleep($seconds);
                }
            }, $this->retryDelaySeconds);

            $channel->basic_consume(Processor::QUEUE, '', false, false, false, false, function (AMQPMessage $delivery) use ($handler): void {
                $message = self::toMessage($delivery);
                $handler->handle($this->processor->process($message), $message, new AmqpDelivery($delivery));
            });
            $this->logger->info('consuming', ['queue' => Processor::QUEUE]);

            while (!$shouldStop() && $channel->is_consuming()) {
                try {
                    $channel->wait(null, false, self::POLL_SECONDS);
                } catch (AMQPTimeoutException) {
                    // nothing arrived: loop to re-check the stop flag
                }
            }
        } finally {
            $this->channel = null;
            try {
                $channel->close();
                $connection->close();
            } catch (\Throwable) {
                // closing on the way out
            }
        }
    }

    public function move(string $queue, string $body, string $messageId, int $retries): void
    {
        $channel = $this->channel ?? throw new \LogicException('not consuming');
        $channel->basic_publish(new AMQPMessage($body, [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'message_id' => $messageId,
            'application_headers' => new AMQPTable([self::RETRY_HEADER => $retries]),
        ]), '', $queue);
        $channel->wait_for_pending_acks(self::CONFIRM_TIMEOUT_SECONDS);
    }

    private static function toMessage(AMQPMessage $delivery): Message
    {
        $retries = 0;
        if ($delivery->has('application_headers')) {
            $table = $delivery->get('application_headers');
            $value = $table instanceof AMQPTable ? ($table->getNativeData()[self::RETRY_HEADER] ?? 0) : 0;
            $retries = is_int($value) ? $value : 0;
        }
        $id = $delivery->has('message_id') ? $delivery->get('message_id') : '';

        return new Message(is_string($id) ? $id : '', $delivery->getBody(), $retries);
    }
}
