<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\OrderStatus;
use App\Domain\OrderStatusChanged;
use App\Infrastructure\Messaging\AmqpConnection;
use App\Infrastructure\Messaging\AmqpConsumer;
use App\Infrastructure\Messaging\AmqpPublisher;
use App\Infrastructure\Messaging\Notifier;
use App\Infrastructure\Messaging\PermanentFailure;
use App\Infrastructure\Messaging\Processor;
use App\Infrastructure\Messaging\SeenSet;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\NativeClock;
use Throwable;

/**
 * Integration tests against a real RabbitMQ (BROKER_URL), e.g. BROKER_URL=amqp://guest:guest@127.0.0.1:5672/
 * Skipped when the variable is not set. They share the fixed queue names, so they run one at a time.
 */
final class AmqpTest extends TestCase
{
    private string $url;

    protected function setUp(): void
    {
        $url = getenv('BROKER_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('BROKER_URL not set');
        }
        $this->url = $url;
        $channel = AmqpConnection::open($url)->channel();
        AmqpConnection::declareTopology($channel);
        $channel->queue_purge(Processor::QUEUE);
        $channel->queue_purge(Processor::DEAD_LETTER_QUEUE);
        $channel->close();
    }

    private static function event(): OrderStatusChanged
    {
        return new OrderStatusChanged(
            '0b9d3bfa-1d54-4f32-9d0e-6a8a4b7b6a11',
            'ana@example.com',
            'Ana',
            OrderStatus::Open,
            OrderStatus::Confirmed,
            BigDecimal::of('10.30'),
            new DateTimeImmutable('2026-01-02T03:04:05Z'),
        );
    }

    /** Consumes until the notifier has been called $calls times (or 15 seconds pass). */
    private function consume(CountingNotifier $notifier, int $calls): void
    {
        $processor = new Processor($notifier, new SeenSet(new NativeClock()), new NullLogger());
        $consumer = new AmqpConsumer($this->url, $processor, new NullLogger(), retryDelaySeconds: 0);
        $deadline = time() + 15;
        $consumer->consume(static fn(): bool => count($notifier->events) >= $calls || time() > $deadline);
    }

    private function deadLetter(): ?AMQPMessage
    {
        $channel = AmqpConnection::open($this->url)->channel();
        for ($i = 0; $i < 50; ++$i) {
            $message = $channel->basic_get(Processor::DEAD_LETTER_QUEUE, true);
            if ($message instanceof AMQPMessage) {
                return $message;
            }
            usleep(100_000);
        }

        return null;
    }

    public function test_a_published_event_reaches_the_notifier_with_a_message_id(): void
    {
        $publisher = new AmqpPublisher($this->url);
        $publisher->publishStatusChanged(self::event());
        $notifier = new CountingNotifier();

        $this->consume($notifier, 1);

        self::assertCount(1, $notifier->events);
        self::assertSame(self::event()->orderId, $notifier->events[0]->orderId);
        self::assertSame('ana@example.com', $notifier->events[0]->customerEmail);
    }

    public function test_a_permanent_failure_goes_straight_to_the_dead_letter_queue(): void
    {
        (new AmqpPublisher($this->url))->publishStatusChanged(self::event());
        $notifier = new CountingNotifier(new PermanentFailure('bad recipient'));

        $this->consume($notifier, 1);
        $dead = $this->deadLetter();

        self::assertNotNull($dead);
        self::assertNotSame('', (string) $dead->get('message_id'));
        self::assertSame(1, count($notifier->events), 'a permanent failure must not be retried');
    }

    public function test_a_transient_failure_is_retried_then_dead_lettered_with_the_original_acked(): void
    {
        (new AmqpPublisher($this->url))->publishStatusChanged(self::event());
        $notifier = new CountingNotifier(new RuntimeException('smtp down'));

        $this->consume($notifier, Processor::MAX_RETRIES + 1);
        $dead = $this->deadLetter();

        self::assertNotNull($dead);
        self::assertSame(Processor::MAX_RETRIES + 1, count($notifier->events));
        $headers = $dead->get('application_headers')->getNativeData();
        self::assertSame(Processor::MAX_RETRIES, $headers['x-retry-count']);
        $channel = AmqpConnection::open($this->url)->channel();
        self::assertNull($channel->basic_get(Processor::QUEUE), 'the original must have been acked after the DLQ publish');
    }
}

final class CountingNotifier implements Notifier
{
    /** @var list<OrderStatusChanged> */
    public array $events = [];

    public function __construct(private readonly ?Throwable $failure = null) {}

    public function notify(OrderStatusChanged $event): void
    {
        $this->events[] = $event;
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
