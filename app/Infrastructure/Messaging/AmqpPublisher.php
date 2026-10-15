<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Application\EventPublisher;
use App\Domain\OrderStatusChanged;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Ramsey\Uuid\Uuid;

/**
 * The EventPublisher backed by RabbitMQ. It connects lazily (an unavailable broker at startup does not stop the
 * API: publishing is best effort anyway) and waits for the broker's publisher confirm, so a publish only counts
 * once the broker has the message.
 */
final class AmqpPublisher implements EventPublisher
{
    private const int CONFIRM_TIMEOUT_SECONDS = 5;

    private ?AbstractConnection $connection = null;

    private ?AMQPChannel $channel = null;

    public function __construct(private readonly string $url) {}

    public function publishStatusChanged(OrderStatusChanged $event): void
    {
        $channel = $this->channel();
        $channel->basic_publish(new AMQPMessage(json_encode($event->toArray(), JSON_THROW_ON_ERROR), [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'message_id' => Uuid::uuid4()->toString(),
            'timestamp' => time(),
        ]), '', Processor::QUEUE);
        $channel->wait_for_pending_acks(self::CONFIRM_TIMEOUT_SECONDS);
    }

    public function __destruct()
    {
        try {
            $this->channel?->close();
            $this->connection?->close();
        } catch (\Throwable) {
            // closing on the way out: nothing useful to do with a failure
        }
    }

    private function channel(): AMQPChannel
    {
        if ($this->connection === null || !$this->connection->isConnected() || $this->channel === null) {
            $this->connection = AmqpConnection::open($this->url);
            $this->channel = $this->connection->channel();
            AmqpConnection::declareTopology($this->channel);
            $this->channel->confirm_select();
        }

        return $this->channel;
    }
}
