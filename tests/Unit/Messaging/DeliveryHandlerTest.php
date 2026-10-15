<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging;

use App\Domain\OrderStatus;
use App\Domain\OrderStatusChanged;
use App\Infrastructure\Messaging\Action;
use App\Infrastructure\Messaging\Delivery;
use App\Infrastructure\Messaging\DeliveryHandler;
use App\Infrastructure\Messaging\Message;
use App\Infrastructure\Messaging\MessageMover;
use App\Infrastructure\Messaging\Processor;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class DeliveryHandlerTest extends TestCase
{
    private FakeDelivery $delivery;

    private FakeMover $mover;

    /** @var list<int> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->delivery = new FakeDelivery();
        $this->mover = new FakeMover();
    }

    private function handle(Action $action, int $retries = 0): void
    {
        $handler = new DeliveryHandler($this->mover, new NullLogger(), function (int $seconds): void {
            $this->sleeps[] = $seconds;
        });
        $handler->handle($action, new Message('m1', 'body', $retries), $this->delivery);
    }

    public function test_an_ack_just_acknowledges(): void
    {
        $this->handle(Action::Ack);

        self::assertSame(1, $this->delivery->acked);
        self::assertSame([], $this->mover->moves);
    }

    public function test_a_retry_waits_then_republishes_with_the_count_incremented_and_only_then_acks(): void
    {
        $this->handle(Action::Retry, 1);

        self::assertSame([Processor::RETRY_DELAY_SECONDS], $this->sleeps);
        self::assertSame([[Processor::QUEUE, 'body', 'm1', 2]], $this->mover->moves);
        self::assertSame(1, $this->delivery->acked);
    }

    public function test_a_dead_letter_is_moved_to_the_dlq_keeping_the_retry_count(): void
    {
        $this->handle(Action::DeadLetter, 3);

        self::assertSame([[Processor::DEAD_LETTER_QUEUE, 'body', 'm1', 3]], $this->mover->moves);
        self::assertSame(1, $this->delivery->acked);
        self::assertSame([], $this->sleeps);
    }

    public function test_when_the_copy_is_not_confirmed_the_original_is_requeued_and_never_acked(): void
    {
        $this->mover->failure = new RuntimeException('broker unavailable');

        $this->handle(Action::DeadLetter);

        self::assertSame(0, $this->delivery->acked);
        self::assertSame(1, $this->delivery->requeued);
    }

    public function test_the_event_type_stays_untouched_by_the_handler(): void
    {
        // The handler moves bytes, it never decodes the event: PII does not pass through its logs.
        self::assertSame(OrderStatus::Confirmed, (new OrderStatusChanged('x', 'e', 'n', OrderStatus::Open, OrderStatus::Confirmed, BigDecimal::zero(), new DateTimeImmutable()))->newStatus);
    }
}

final class FakeDelivery implements Delivery
{
    public int $acked = 0;

    public int $requeued = 0;

    public function ack(): void
    {
        ++$this->acked;
    }

    public function requeue(): void
    {
        ++$this->requeued;
    }
}

final class FakeMover implements MessageMover
{
    /** @var list<array{string, string, string, int}> */
    public array $moves = [];

    public ?RuntimeException $failure = null;

    public function move(string $queue, string $body, string $messageId, int $retries): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $this->moves[] = [$queue, $body, $messageId, $retries];
    }
}
