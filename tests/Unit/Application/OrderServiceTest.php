<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Actor;
use App\Application\OrderService;
use App\Domain\Error\ApiException;
use App\Domain\Error\ErrorCode;
use App\Domain\ItemInput;
use App\Domain\OrderStatus;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Tests\Support\InMemoryStore;
use Tests\Support\RecordingPublisher;

final class OrderServiceTest extends TestCase
{
    private InMemoryStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
    }

    private function service(?RecordingPublisher $publisher = null): OrderService
    {
        return new OrderService($this->store, $publisher, new MockClock('2026-10-14 12:00:00'), new NullLogger());
    }

    private static function widget(string $price = '1', int $quantity = 1): ItemInput
    {
        return new ItemInput('Widget', BigDecimal::of($price), $quantity);
    }

    private static function user(string $id = 'user-1'): Actor
    {
        return new Actor($id, ['user']);
    }

    private static function assertApiError(ErrorCode $code, callable $action): void
    {
        try {
            $action();
        } catch (ApiException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());

            return;
        }
        self::fail("expected an ApiException with code {$code->value}");
    }

    public function test_create_opens_an_order_locking_the_customer_for_share_in_the_same_transaction(): void
    {
        $customer = $this->store->seedCustomer();

        $order = $this->service()->create((string) $customer->id, [self::widget('2.50', 4)], self::user());

        self::assertSame(OrderStatus::Open, $order->status);
        self::assertTrue($order->total()->isEqualTo('10'));
        self::assertSame('user-1', $order->createdBy);
        self::assertSame(['begin', 'customer.lock-share', 'order.create', 'commit'], $this->store->calls);
    }

    public function test_a_customer_that_does_not_exist_is_validation_07_not_404(): void
    {
        self::assertApiError(
            ErrorCode::CustomerNotFoundInBody,
            fn() => $this->service()->create(Uuid::uuid4()->toString(), [], self::user()),
        );
        self::assertSame('rollback', $this->store->lastCall());
    }

    public function test_a_soft_deleted_customer_is_treated_as_missing(): void
    {
        $customer = $this->store->seedCustomer();
        $this->store->deletedCustomers[(string) $customer->id] = true;

        self::assertApiError(ErrorCode::CustomerNotFoundInBody, fn() => $this->service()->create((string) $customer->id, [], self::user()));
    }

    public function test_an_invalid_item_stores_nothing(): void
    {
        $customer = $this->store->seedCustomer();

        self::assertApiError(ErrorCode::InvalidRequest, fn() => $this->service()->create((string) $customer->id, [self::widget('0')], self::user()));

        self::assertSame([], $this->store->orders);
    }

    public function test_item_operations_keep_the_stored_total_in_sync(): void
    {
        $customer = $this->store->seedCustomer();
        $service = $this->service();
        $order = $service->create((string) $customer->id, [self::widget('10')], self::user());
        $orderId = (string) $order->id;

        $order = $service->addItem($orderId, self::widget('5', 2), self::user());
        self::assertTrue($order->total()->isEqualTo('20'));
        self::assertSame(2, $order->version);

        $order = $service->updateItemQuantity($orderId, (string) $order->items[0]->id, 3, self::user());
        self::assertTrue($order->total()->isEqualTo('40'));

        $order = $service->removeItem($orderId, (string) $order->items[0]->id, self::user());
        self::assertTrue($order->total()->isEqualTo('10'));
        self::assertCount(1, $order->items);

        self::assertTrue($service->get($orderId)->total()->isEqualTo('10'));
        self::assertApiError(ErrorCode::ItemNotFound, fn() => $service->updateItemQuantity($orderId, Uuid::uuid4()->toString(), 1, self::user()));
        self::assertApiError(ErrorCode::OrderNotFound, fn() => $service->addItem(Uuid::uuid4()->toString(), self::widget(), self::user()));
    }

    public function test_confirm_and_cancel_publish_the_event(): void
    {
        $customer = $this->store->seedCustomer('ada@example.com');
        $publisher = new RecordingPublisher();
        $service = $this->service($publisher);
        $order = $service->create((string) $customer->id, [self::widget('2.50', 4)], self::user());

        $confirmed = $service->confirm((string) $order->id, self::user('approver'));

        self::assertSame(OrderStatus::Confirmed, $confirmed->status);
        self::assertCount(1, $publisher->events);
        $event = $publisher->events[0];
        self::assertSame($order->id, $event->orderId);
        self::assertSame('ada@example.com', $event->customerEmail);
        self::assertSame('Ada', $event->customerName);
        self::assertSame(OrderStatus::Open, $event->oldStatus);
        self::assertSame(OrderStatus::Confirmed, $event->newStatus);
        self::assertTrue($event->totalAmount->isEqualTo('10'));
        self::assertEquals(new DateTimeImmutable('2026-10-14T12:00:00Z'), $event->changedAt);

        $service->cancel((string) $order->id, self::user('approver'));

        self::assertCount(2, $publisher->events);
        self::assertSame(OrderStatus::Confirmed, $publisher->events[1]->oldStatus);
        self::assertSame(OrderStatus::Canceled, $publisher->events[1]->newStatus);
    }

    public function test_no_event_for_a_customer_without_an_email(): void
    {
        $customer = $this->store->seedCustomer('');
        $publisher = new RecordingPublisher();
        $service = $this->service($publisher);
        $order = $service->create((string) $customer->id, [self::widget()], self::user());

        $service->confirm((string) $order->id, self::user());

        self::assertSame([], $publisher->events);
    }

    public function test_a_failed_transition_publishes_nothing(): void
    {
        $customer = $this->store->seedCustomer();
        $publisher = new RecordingPublisher();
        $service = $this->service($publisher);
        $order = $service->create((string) $customer->id, [], self::user());

        self::assertApiError(ErrorCode::OrderEmpty, fn() => $service->confirm((string) $order->id, self::user()));

        self::assertSame([], $publisher->events);
    }

    public function test_it_works_without_a_publisher(): void
    {
        $customer = $this->store->seedCustomer();
        $service = $this->service(null);
        $order = $service->create((string) $customer->id, [self::widget()], self::user());

        self::assertSame(OrderStatus::Confirmed, $service->confirm((string) $order->id, self::user())->status);
    }

    public function test_a_broker_failure_never_fails_the_transition(): void
    {
        $customer = $this->store->seedCustomer();
        $publisher = new RecordingPublisher();
        $publisher->failure = new RuntimeException('broker down');
        $service = $this->service($publisher);
        $order = $service->create((string) $customer->id, [self::widget()], self::user());

        $confirmed = $service->confirm((string) $order->id, self::user());

        self::assertSame(OrderStatus::Confirmed, $confirmed->status);
        self::assertSame(OrderStatus::Confirmed, $service->get((string) $order->id)->status);
    }

    public function test_rule_violations_keep_their_codes(): void
    {
        $customer = $this->store->seedCustomer();
        $service = $this->service();
        $order = $service->create((string) $customer->id, [self::widget()], self::user());
        $id = (string) $order->id;

        $service->confirm($id, self::user());
        self::assertApiError(ErrorCode::InvalidTransition, fn() => $service->confirm($id, self::user()));
        self::assertApiError(ErrorCode::OrderNotEditable, fn() => $service->addItem($id, self::widget(), self::user()));
        $service->cancel($id, self::user());
        self::assertApiError(ErrorCode::AlreadyCanceled, fn() => $service->cancel($id, self::user()));
    }

    public function test_a_concurrent_modification_is_a_conflict_not_an_overwrite(): void
    {
        $customer = $this->store->seedCustomer();
        $service = $this->service();
        $order = $service->create((string) $customer->id, [self::widget()], self::user());
        $id = (string) $order->id;
        // Someone else commits a change to the same order between our read and our save.
        $this->store->beforeOrderSave = function (InMemoryStore $store) use ($id): void {
            $store->orders[$id]->versionIncremented();
        };

        self::assertApiError(ErrorCode::ConcurrentModification, fn() => $service->addItem($id, self::widget(), self::user()));

        self::assertCount(1, $this->store->orders[$id]->items, 'the losing write must not be applied');
    }

    public function test_delete_soft_deletes_the_order(): void
    {
        $customer = $this->store->seedCustomer();
        $service = $this->service();
        $order = $service->create((string) $customer->id, [], self::user());

        $service->delete((string) $order->id, self::user());

        self::assertApiError(ErrorCode::OrderNotFound, fn() => $service->get((string) $order->id));
        self::assertApiError(ErrorCode::OrderNotFound, fn() => $service->delete(Uuid::uuid4()->toString(), self::user()));
    }
}
