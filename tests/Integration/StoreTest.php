<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\Condition;
use App\Application\Repositories;
use App\Application\SearchRequest;
use App\Domain\Customer;
use App\Domain\CustomerInput;
use App\Domain\Error\ApiException;
use App\Domain\Error\ErrorCode;
use App\Domain\ItemInput;
use App\Domain\Order;
use App\Domain\OrderStatus;
use App\Infrastructure\Postgres\PostgresStore;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class StoreTest extends DatabaseTestCase
{
    private PostgresStore $store;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new PostgresStore($this->db);
        $this->now = new DateTimeImmutable('2026-01-02T03:04:05Z');
    }

    private function newCustomer(string $taxId = '12345678', ?string $passport = null): Customer
    {
        $customer = Customer::create(new CustomerInput('Ana', $taxId, $passport, 'ana@example.com'), 'tester', $this->now);
        $this->store->repos()->customers->create($customer);

        return $customer;
    }

    private function newOrder(string $customerId): Order
    {
        $order = Order::open($customerId, [new ItemInput('Pen', BigDecimal::of('2.50'), 4)], 'tester', $this->now);
        $this->store->repos()->orders->create($order);

        return $order;
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

    public function test_a_customer_round_trips_with_a_database_generated_id(): void
    {
        $created = $this->newCustomer('12345678', 'AB123456');

        $loaded = $this->store->repos()->customers->get((string) $created->id);

        self::assertTrue(Uuid::isValid((string) $loaded->id));
        self::assertSame('12345678', $loaded->taxId);
        self::assertSame('AB123456', $loaded->passportNumber);
        self::assertSame(1, $loaded->version);
        self::assertEquals($this->now, $loaded->createdAt);
    }

    public function test_uniqueness_is_enforced_by_the_database_and_mapped_to_the_spec_codes(): void
    {
        $this->newCustomer('12345678', 'AB123456');
        $duplicateTax = Customer::create(new CustomerInput('B', '12345678', null, 'b@example.com'), 't', $this->now);
        $duplicatePassport = Customer::create(new CustomerInput('C', '87654321', 'AB123456', 'c@example.com'), 't', $this->now);

        self::assertApiError(ErrorCode::DuplicateTaxId, fn() => $this->store->repos()->customers->create($duplicateTax));
        self::assertApiError(ErrorCode::DuplicatePassport, fn() => $this->store->repos()->customers->create($duplicatePassport));
    }

    public function test_a_soft_deleted_customer_is_hidden_and_releases_its_unique_values(): void
    {
        $customers = $this->store->repos()->customers;
        $customer = $this->newCustomer('12345678');

        $customers->softDelete((string) $customer->id, 'admin', $this->now);

        self::assertApiError(ErrorCode::CustomerNotFound, fn() => $customers->get((string) $customer->id));
        self::assertFalse($customers->taxIdTaken('12345678', null));
        self::assertNotSame($customer->id, $this->newCustomer('12345678')->id);
    }

    public function test_update_checks_the_version(): void
    {
        $customers = $this->store->repos()->customers;
        $created = $this->newCustomer();
        $stale = $customers->get((string) $created->id);
        $fresh = $customers->get((string) $created->id);
        $fresh->replace(new CustomerInput('New', '12345678', null, 'n@example.com'), 'u', $this->now);
        $stale->replace(new CustomerInput('Old', '12345678', null, 'o@example.com'), 'u', $this->now);

        $customers->update($fresh);

        self::assertSame(2, $fresh->version);
        self::assertApiError(ErrorCode::ConcurrentModification, fn() => $customers->update($stale));
        self::assertSame('New', $customers->get((string) $created->id)->name);
    }

    public function test_an_order_round_trips_with_items_in_order_and_an_exact_total(): void
    {
        $orders = $this->store->repos()->orders;
        $order = $this->newOrder((string) $this->newCustomer()->id);
        $order->addItem(new ItemInput('Ink', BigDecimal::of('0.10'), 3), 'u', $this->now);
        $orders->save($order);

        $loaded = $orders->get((string) $order->id);

        self::assertCount(2, $loaded->items);
        self::assertSame(['Pen', 'Ink'], array_map(static fn($i) => $i->description, $loaded->items));
        self::assertTrue($loaded->total()->isEqualTo('10.30'));
        self::assertSame(2, $loaded->version);
        self::assertNotNull($loaded->items[1]->id);
    }

    public function test_save_applies_quantity_changes_and_removals(): void
    {
        $orders = $this->store->repos()->orders;
        $order = $this->newOrder((string) $this->newCustomer()->id);
        $order->addItem(new ItemInput('Ink', BigDecimal::of('1'), 1), 'u', $this->now);
        $orders->save($order);
        $loaded = $orders->get((string) $order->id);
        $loaded->updateItemQuantity((string) $loaded->items[0]->id, 9, 'u', $this->now);
        $loaded->removeItem((string) $loaded->items[1]->id, 'u', $this->now);

        $orders->save($loaded);
        $reloaded = $orders->get((string) $order->id);

        self::assertCount(1, $reloaded->items);
        self::assertSame(9, $reloaded->items[0]->quantity);
    }

    public function test_save_rejects_a_stale_version_and_the_loser_does_not_overwrite(): void
    {
        $orders = $this->store->repos()->orders;
        $order = $this->newOrder((string) $this->newCustomer()->id);
        $a = $orders->get((string) $order->id);
        $b = $orders->get((string) $order->id);
        $a->confirm('u', $this->now);
        $b->cancel('u', $this->now);

        $orders->save($a);

        self::assertApiError(ErrorCode::ConcurrentModification, fn() => $orders->save($b));
        self::assertSame(OrderStatus::Confirmed, $orders->get((string) $order->id)->status);
    }

    public function test_has_active_ignores_deleted_orders(): void
    {
        $orders = $this->store->repos()->orders;
        $customer = $this->newCustomer();
        $order = $this->newOrder((string) $customer->id);

        self::assertTrue($orders->hasActive((string) $customer->id));
        $orders->softDelete((string) $order->id, 'admin', $this->now);

        self::assertFalse($orders->hasActive((string) $customer->id));
        self::assertApiError(ErrorCode::OrderNotFound, fn() => $orders->get((string) $order->id));
    }

    public function test_a_malformed_id_is_simply_not_found(): void
    {
        self::assertApiError(ErrorCode::CustomerNotFound, fn() => $this->store->repos()->customers->get('not-a-uuid'));
        self::assertApiError(ErrorCode::OrderNotFound, fn() => $this->store->repos()->orders->get('123'));
    }

    public function test_order_search_filters_sorts_pages_and_loads_items(): void
    {
        $orders = $this->store->repos()->orders;
        $customer = $this->newCustomer();
        $this->newOrder((string) $customer->id);
        $second = $this->newOrder((string) $customer->id);
        $loaded = $orders->get((string) $second->id);
        $loaded->confirm('u', $this->now);
        $orders->save($loaded);

        $confirmed = $orders->search(new SearchRequest(0, 10, null, ['status' => [new Condition('eq', 'CONFIRMED')]]));
        $paged = $orders->search(new SearchRequest(1, 1, '-created_at'));

        self::assertSame(1, $confirmed->total);
        self::assertSame($second->id, $confirmed->items[0]->id);
        self::assertCount(1, $confirmed->items[0]->items);
        self::assertSame(2, $paged->total);
        self::assertCount(1, $paged->items);
    }

    public function test_customer_search_like_is_a_contains_match_and_escapes_wildcards(): void
    {
        $customers = $this->store->repos()->customers;
        $this->newCustomer();

        $hit = $customers->search(new SearchRequest(0, 10, null, ['name' => [new Condition('lk', 'n')]]));
        $miss = $customers->search(new SearchRequest(0, 10, null, ['name' => [new Condition('lk', '%')]]));

        self::assertSame(1, $hit->total);
        self::assertSame(0, $miss->total);
    }

    public function test_a_transaction_rolls_back_on_error(): void
    {
        $customer = $this->newCustomer();

        try {
            $this->store->inTransaction(function (Repositories $repos) use ($customer): void {
                $repos->orders->create(Order::open((string) $customer->id, [], 'u', $this->now));
                throw ApiException::validation(ErrorCode::InvalidRequest, 'boom');
            });
        } catch (ApiException) {
            // expected: the callback always fails
        }

        self::assertSame(0, $this->store->repos()->orders->search(new SearchRequest(0, 10))->total);
    }

    public function test_a_share_lock_blocks_a_concurrent_delete_and_the_other_way_round(): void
    {
        // The race the locking exists for: deleting a customer while an order is created for it. Each side's lock
        // must exclude the other, seen here from a second connection that gives up after 300 ms.
        $customer = $this->newCustomer();
        $id = (string) $customer->id;
        $other = DB::connectUsing('second', (array) config('database.connections.pgsql'), true);
        $other->statement("SET lock_timeout = '300ms'");
        $otherStore = new PostgresStore($other);

        $this->store->inTransaction(function (Repositories $repos) use ($id, $otherStore): void {
            $repos->customers->lockForShare($id); // an order is being created
            $this->expectLockTimeout(fn() => $otherStore->repos()->customers->lockForUpdate($id));
        });

        $this->store->inTransaction(function (Repositories $repos) use ($id, $otherStore): void {
            $repos->customers->lockForUpdate($id); // the customer is being deleted
            $this->expectLockTimeout(fn() => $otherStore->repos()->customers->lockForShare($id));
        });
    }

    private function expectLockTimeout(callable $action): void
    {
        try {
            $action();
        } catch (QueryException $e) {
            self::assertStringContainsString('lock timeout', strtolower($e->getMessage()));

            return;
        }
        self::fail('the lock was granted although it must be excluded');
    }
}
