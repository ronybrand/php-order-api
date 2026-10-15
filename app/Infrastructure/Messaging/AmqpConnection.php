<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use InvalidArgumentException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Wire\AMQPTable;

/** Opens connections from the broker URL (amqps:// verifies the server certificate) and declares the topology. */
final class AmqpConnection
{
    public static function open(string $url): AbstractConnection
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['scheme']) || !in_array($parts['scheme'], ['amqp', 'amqps'], true)) {
            throw new InvalidArgumentException('BROKER_URL must be an amqp:// or amqps:// URL');
        }
        $user = rawurldecode($parts['user'] ?? 'guest');
        $password = rawurldecode($parts['pass'] ?? 'guest');
        $vhost = isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/' ? rawurldecode(substr($parts['path'], 1)) : '/';

        if ($parts['scheme'] === 'amqps') {
            return new AMQPSSLConnection($parts['host'], $parts['port'] ?? 5671, $user, $password, $vhost, [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ]);
        }

        return new AMQPStreamConnection($parts['host'], $parts['port'] ?? 5672, $user, $password, $vhost);
    }

    /** Idempotent: the main queue dead-letters to the DLQ through the default exchange, and both are durable. */
    public static function declareTopology(AMQPChannel $channel): void
    {
        $channel->queue_declare(Processor::DEAD_LETTER_QUEUE, false, true, false, false);
        $channel->queue_declare(Processor::QUEUE, false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange' => '',
            'x-dead-letter-routing-key' => Processor::DEAD_LETTER_QUEUE,
        ]));
    }
}
