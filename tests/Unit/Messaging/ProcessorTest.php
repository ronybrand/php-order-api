<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging;

use App\Domain\OrderStatus;
use App\Domain\OrderStatusChanged;
use App\Infrastructure\Messaging\Action;
use App\Infrastructure\Messaging\Message;
use App\Infrastructure\Messaging\Notifier;
use App\Infrastructure\Messaging\PermanentFailure;
use App\Infrastructure\Messaging\Processor;
use App\Infrastructure\Messaging\SeenSet;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Throwable;

final class ProcessorTest extends TestCase
{
    private ScriptedNotifier $notifier;

    private Processor $processor;

    protected function setUp(): void
    {
        $this->notifier = new ScriptedNotifier();
        $this->processor = new Processor($this->notifier, new SeenSet(new MockClock('2026-10-14 12:00:00')), new NullLogger());
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

    private static function message(string $id = 'm1', int $retries = 0): Message
    {
        return new Message($id, json_encode(self::event()->toArray(), JSON_THROW_ON_ERROR), $retries);
    }

    public function test_the_event_wire_format_round_trips_in_snake_case(): void
    {
        $array = self::event()->toArray();

        $back = OrderStatusChanged::fromArray($array);

        self::assertArrayHasKey('order_id', $array);
        self::assertArrayHasKey('customer_email', $array);
        self::assertSame(self::event()->orderId, $back->orderId);
        self::assertTrue($back->totalAmount->isEqualTo('10.30'));
        self::assertSame(OrderStatus::Confirmed, $back->newStatus);
        self::assertEquals(self::event()->changedAt, $back->changedAt);
    }

    public function test_acks_after_notifying(): void
    {
        self::assertSame(Action::Ack, $this->processor->process(self::message()));
        self::assertCount(1, $this->notifier->events);
        self::assertSame('ana@example.com', $this->notifier->events[0]->customerEmail);
    }

    public function test_deduplicates_by_message_id(): void
    {
        $first = $this->processor->process(self::message('same'));
        $redelivery = $this->processor->process(self::message('same'));
        $other = $this->processor->process(self::message('different'));

        self::assertSame([Action::Ack, Action::Ack, Action::Ack], [$first, $redelivery, $other]);
        self::assertCount(2, $this->notifier->events, 'a redelivered message must be acked without a second e-mail');
    }

    public function test_a_failed_attempt_is_not_marked_as_done(): void
    {
        $this->notifier->failure = new RuntimeException('smtp down');
        $first = $this->processor->process(self::message('m1', 0));
        $this->notifier->failure = null;

        $second = $this->processor->process(self::message('m1', 1));

        self::assertSame(Action::Retry, $first);
        self::assertSame(Action::Ack, $second);
        self::assertCount(2, $this->notifier->events);
    }

    public function test_retries_then_dead_letters(): void
    {
        $this->notifier->failure = new RuntimeException('smtp down');

        for ($retries = 0; $retries < Processor::MAX_RETRIES; ++$retries) {
            self::assertSame(Action::Retry, $this->processor->process(self::message("m{$retries}", $retries)), "retries={$retries}");
        }
        self::assertSame(Action::DeadLetter, $this->processor->process(self::message('last', Processor::MAX_RETRIES)));
    }

    public function test_a_permanent_failure_skips_the_retries(): void
    {
        $this->notifier->failure = new PermanentFailure('bad recipient');

        self::assertSame(Action::DeadLetter, $this->processor->process(self::message('m1', 0)));
    }

    public function test_poison_messages_go_straight_to_the_dead_letter_queue_and_never_reach_the_notifier(): void
    {
        $poison = ['nope', '', '{"order_id":"x"}', '[]', '{"order_id":"a","customer_email":1}'];
        foreach ($poison as $body) {
            self::assertSame(Action::DeadLetter, $this->processor->process(new Message('p', $body, 0)), $body);
        }
        self::assertSame([], $this->notifier->events);
    }

    public function test_the_seen_set_forgets_after_the_ttl_and_stays_bounded(): void
    {
        $clock = new MockClock('2026-10-14 12:00:00');
        $seen = new SeenSet($clock, ttlSeconds: 3600, capacity: 3);

        $seen->add('a');
        self::assertTrue($seen->has('a'));
        $clock->sleep(7200);
        self::assertFalse($seen->has('a'), 'a should have expired');

        foreach (['1', '2', '3', '4'] as $id) {
            $seen->add($id);
        }
        self::assertLessThanOrEqual(3, $seen->size());
        self::assertTrue($seen->has('4'), 'the newest id must survive eviction');
    }
}

final class ScriptedNotifier implements Notifier
{
    /** @var list<OrderStatusChanged> */
    public array $events = [];

    public ?Throwable $failure = null;

    public function notify(OrderStatusChanged $event): void
    {
        $this->events[] = $event;
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
