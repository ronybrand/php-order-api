<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Actor;
use App\Application\CustomerService;
use App\Domain\CustomerInput;
use App\Domain\Error\ApiException;
use App\Domain\Error\ErrorCode;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Tests\Support\InMemoryStore;

final class CustomerServiceTest extends TestCase
{
    private InMemoryStore $store;

    private CustomerService $service;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
        $this->service = new CustomerService($this->store, new MockClock('2026-10-14 12:00:00'), new NullLogger());
    }

    private static function input(string $taxId = 'AB123456', ?string $passport = null, string $name = 'Ada Lovelace'): CustomerInput
    {
        return new CustomerInput($name, $taxId, $passport, 'ada@example.com');
    }

    private static function admin(): Actor
    {
        return new Actor('admin-1', ['admin']);
    }

    private static function assertApiError(ErrorCode $code, callable $action): ApiException
    {
        try {
            $action();
        } catch (ApiException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());

            return $e;
        }
        self::fail("expected an ApiException with code {$code->value}");
    }

    public function test_create_stores_the_audit_from_the_actor_and_the_injected_clock(): void
    {
        $customer = $this->service->create(self::input(), self::admin());

        self::assertNotNull($customer->id);
        self::assertSame('admin-1', $customer->createdBy);
        self::assertEquals(new DateTimeImmutable('2026-10-14T12:00:00Z'), $customer->createdAt);
    }

    public function test_create_rejects_a_duplicate_tax_id_even_with_other_punctuation(): void
    {
        $this->service->create(self::input('123.456-78'), self::admin());

        self::assertApiError(ErrorCode::DuplicateTaxId, fn() => $this->service->create(self::input('12345678'), self::admin()));
    }

    public function test_create_rejects_a_duplicate_passport(): void
    {
        $this->service->create(self::input('11111111', 'AB123456'), self::admin());

        self::assertApiError(ErrorCode::DuplicatePassport, fn() => $this->service->create(self::input('22222222', 'AB123456'), self::admin()));
    }

    public function test_a_customer_without_passport_never_conflicts_on_it(): void
    {
        $this->service->create(self::input('11111111'), self::admin());
        $this->service->create(self::input('22222222'), self::admin());

        self::assertCount(2, $this->store->customers);
    }

    public function test_the_conflict_never_echoes_the_value(): void
    {
        $this->service->create(self::input('12345678'), self::admin());

        $e = self::assertApiError(ErrorCode::DuplicateTaxId, fn() => $this->service->create(self::input('12345678'), self::admin()));

        self::assertStringNotContainsString('12345678', $e->getMessage() . json_encode($e->params, JSON_THROW_ON_ERROR));
    }

    public function test_invalid_input_never_reaches_the_store(): void
    {
        self::assertApiError(ErrorCode::InvalidRequest, fn() => $this->service->create(self::input(name: ' '), self::admin()));

        self::assertSame([], $this->store->calls);
        self::assertSame([], $this->store->customers);
    }

    public function test_update_may_keep_its_own_tax_id(): void
    {
        $created = $this->service->create(self::input('12345678'), self::admin());

        $updated = $this->service->update((string) $created->id, self::input('12345678', name: 'New'), self::admin());

        self::assertSame('New', $updated->name);
        self::assertSame(2, $updated->version);
    }

    public function test_update_cannot_take_another_customers_tax_id(): void
    {
        $this->service->create(self::input('11111111'), self::admin());
        $second = $this->service->create(self::input('22222222'), self::admin());

        self::assertApiError(ErrorCode::DuplicateTaxId, fn() => $this->service->update((string) $second->id, self::input('11111111'), self::admin()));
    }

    public function test_update_and_get_of_an_unknown_customer(): void
    {
        $unknown = Uuid::uuid4()->toString();

        self::assertApiError(ErrorCode::CustomerNotFound, fn() => $this->service->update($unknown, self::input(), self::admin()));
        self::assertApiError(ErrorCode::CustomerNotFound, fn() => $this->service->get($unknown));
    }

    public function test_delete_locks_the_customer_checks_orders_and_soft_deletes_in_one_transaction(): void
    {
        $customer = $this->store->seedCustomer();

        $this->service->delete((string) $customer->id, self::admin());

        self::assertSame(
            ['begin', 'customer.lock-update', 'order.has-active', 'customer.soft-delete', 'commit'],
            $this->store->calls,
        );
        self::assertApiError(ErrorCode::CustomerNotFound, fn() => $this->service->get((string) $customer->id));
    }

    public function test_delete_is_blocked_by_an_active_order(): void
    {
        $customer = $this->store->seedCustomer();
        $this->store->repos()->orders->create(\App\Domain\Order::open((string) $customer->id, [], 'u', new DateTimeImmutable()));
        $this->store->calls = [];

        self::assertApiError(ErrorCode::CustomerHasOrders, fn() => $this->service->delete((string) $customer->id, self::admin()));

        self::assertSame('rollback', $this->store->lastCall());
        self::assertSame($customer->name, $this->service->get((string) $customer->id)->name);
    }

    public function test_a_soft_deleted_order_does_not_block_the_delete(): void
    {
        $customer = $this->store->seedCustomer();
        $orders = $this->store->repos()->orders;
        $order = \App\Domain\Order::open((string) $customer->id, [], 'u', new DateTimeImmutable());
        $orders->create($order);
        $orders->softDelete((string) $order->id, 'admin', new DateTimeImmutable());

        $this->service->delete((string) $customer->id, self::admin());

        self::assertApiError(ErrorCode::CustomerNotFound, fn() => $this->service->get((string) $customer->id));
    }

    public function test_delete_of_an_unknown_customer(): void
    {
        self::assertApiError(ErrorCode::CustomerNotFound, fn() => $this->service->delete(Uuid::uuid4()->toString(), self::admin()));
    }

    public function test_a_released_tax_id_can_be_registered_again(): void
    {
        $first = $this->service->create(self::input('12345678'), self::admin());
        $this->service->delete((string) $first->id, self::admin());

        $again = $this->service->create(self::input('12345678'), self::admin());

        self::assertNotSame($first->id, $again->id);
    }
}
