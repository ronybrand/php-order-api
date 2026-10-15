<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\CustomerRepository;
use App\Application\OrderRepository;
use App\Application\Page;
use App\Application\Repositories;
use App\Application\SearchRequest;
use App\Application\Store;
use App\Domain\Customer;
use App\Domain\Error\ApiException;
use App\Domain\Error\ErrorCode;
use App\Domain\Order;
use Closure;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * A copy-on-write in-memory Store: transactions roll back by restoring a snapshot, and every write or lock is
 * recorded in $calls, so tests can assert what ran inside which transaction.
 */
final class InMemoryStore implements Store
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, Customer> */
    public array $customers = [];

    /** @var array<string, Order> */
    public array $orders = [];

    /** @var array<string, true> */
    public array $deletedCustomers = [];

    /** @var array<string, true> */
    public array $deletedOrders = [];

    /** @var (Closure(self): void)|null Runs just before an order is saved, to simulate a concurrent writer. */
    public ?Closure $beforeOrderSave = null;

    private bool $inTransaction = false;

    public function repos(): Repositories
    {
        return new Repositories(new InMemoryCustomers($this), new InMemoryOrders($this));
    }

    public function inTransaction(callable $work): mixed
    {
        if ($this->inTransaction) {
            return $work($this->repos());
        }
        $snapshot = [self::copy($this->customers), self::copy($this->orders), $this->deletedCustomers, $this->deletedOrders];
        $this->inTransaction = true;
        $this->calls[] = 'begin';
        try {
            $result = $work($this->repos());
            $this->calls[] = 'commit';

            return $result;
        } catch (Throwable $e) {
            [$this->customers, $this->orders, $this->deletedCustomers, $this->deletedOrders] = $snapshot;
            $this->calls[] = 'rollback';

            throw $e;
        } finally {
            $this->inTransaction = false;
        }
    }

    public function lastCall(): string
    {
        return $this->calls === [] ? '' : $this->calls[array_key_last($this->calls)];
    }

    /** Seeds a stored customer. */
    public function seedCustomer(string $email = 'ada@example.com', string $taxId = 'TAX0001', ?string $passport = null, string $name = 'Ada'): Customer
    {
        $now = new DateTimeImmutable('2026-10-14T12:00:00Z');
        $customer = Customer::reconstitute(Uuid::uuid4()->toString(), $name, $taxId, $passport, $email, 1, $now, $now, 'seed', 'seed');
        $this->customers[(string) $customer->id] = $customer;

        return clone $customer;
    }

    /**
     * @template T of object
     *
     * @param array<string, T> $items
     * @return array<string, T>
     */
    public static function copy(array $items): array
    {
        return array_map(static fn(object $o): object => clone $o, $items);
    }

    /** @return Page<Customer> */
    public function allCustomers(SearchRequest $request): Page
    {
        $active = array_filter($this->customers, fn(Customer $c): bool => !isset($this->deletedCustomers[(string) $c->id]));

        return new Page(array_values(self::copy($active)), count($active), $request->page, $request->size);
    }

    /** @return Page<Order> */
    public function allOrders(SearchRequest $request): Page
    {
        $active = array_filter($this->orders, fn(Order $o): bool => !isset($this->deletedOrders[(string) $o->id]));

        return new Page(array_values(self::copy($active)), count($active), $request->page, $request->size);
    }

    public static function customerNotFound(string $id): ApiException
    {
        return ApiException::notFound(ErrorCode::CustomerNotFound, 'customer not found', ['id' => $id]);
    }

    public static function orderNotFound(string $id): ApiException
    {
        return ApiException::notFound(ErrorCode::OrderNotFound, 'order not found', ['id' => $id]);
    }
}

/** @internal */
final readonly class InMemoryCustomers implements CustomerRepository
{
    public function __construct(private InMemoryStore $store) {}

    public function get(string $id): Customer
    {
        return $this->find($id);
    }

    public function lockForUpdate(string $id): Customer
    {
        $this->store->calls[] = 'customer.lock-update';

        return $this->find($id);
    }

    public function lockForShare(string $id): Customer
    {
        $this->store->calls[] = 'customer.lock-share';

        return $this->find($id);
    }

    public function create(Customer $customer): void
    {
        $this->store->calls[] = 'customer.create';
        $customer->persisted(Uuid::uuid4()->toString());
        $this->store->customers[(string) $customer->id] = clone $customer;
    }

    public function update(Customer $customer): void
    {
        $this->store->calls[] = 'customer.update';
        $stored = $this->store->customers[(string) $customer->id] ?? null;
        if ($stored === null || $stored->version !== $customer->version) {
            throw ApiException::conflict(ErrorCode::ConcurrentModification, 'modified by someone else');
        }
        $customer->versionIncremented();
        $this->store->customers[(string) $customer->id] = clone $customer;
    }

    public function softDelete(string $id, string $actor, DateTimeImmutable $at): void
    {
        $this->store->calls[] = 'customer.soft-delete';
        $this->find($id);
        $this->store->deletedCustomers[$id] = true;
    }

    public function taxIdTaken(string $taxId, ?string $except): bool
    {
        foreach ($this->store->customers as $id => $customer) {
            if ($customer->taxId === $taxId && $id !== $except && !isset($this->store->deletedCustomers[$id])) {
                return true;
            }
        }

        return false;
    }

    public function passportTaken(string $passport, ?string $except): bool
    {
        foreach ($this->store->customers as $id => $customer) {
            if ($customer->passportNumber === $passport && $id !== $except && !isset($this->store->deletedCustomers[$id])) {
                return true;
            }
        }

        return false;
    }

    public function search(SearchRequest $request): Page
    {
        return $this->store->allCustomers($request);
    }

    private function find(string $id): Customer
    {
        $customer = $this->store->customers[$id] ?? null;
        if ($customer === null || isset($this->store->deletedCustomers[$id])) {
            throw InMemoryStore::customerNotFound($id);
        }

        return clone $customer;
    }
}

/** @internal */
final readonly class InMemoryOrders implements OrderRepository
{
    public function __construct(private InMemoryStore $store) {}

    public function get(string $id): Order
    {
        $order = $this->store->orders[$id] ?? null;
        if ($order === null || isset($this->store->deletedOrders[$id])) {
            throw InMemoryStore::orderNotFound($id);
        }

        return clone $order;
    }

    public function create(Order $order): void
    {
        $this->store->calls[] = 'order.create';
        $order->persisted(Uuid::uuid4()->toString());
        $this->assignItemIds($order);
        $this->store->orders[(string) $order->id] = clone $order;
    }

    public function save(Order $order): void
    {
        $this->store->calls[] = 'order.save';
        if ($this->store->beforeOrderSave !== null) {
            ($this->store->beforeOrderSave)($this->store);
        }
        $stored = $this->store->orders[(string) $order->id] ?? null;
        if ($stored === null || $stored->version !== $order->version) {
            throw ApiException::conflict(ErrorCode::ConcurrentModification, 'modified by someone else');
        }
        $this->assignItemIds($order);
        $order->versionIncremented();
        $this->store->orders[(string) $order->id] = clone $order;
    }

    public function softDelete(string $id, string $actor, DateTimeImmutable $at): void
    {
        $this->store->calls[] = 'order.soft-delete';
        $this->get($id);
        $this->store->deletedOrders[$id] = true;
    }

    public function hasActive(string $customerId): bool
    {
        $this->store->calls[] = 'order.has-active';
        foreach ($this->store->orders as $id => $order) {
            if ($order->customerId === $customerId && !isset($this->store->deletedOrders[$id])) {
                return true;
            }
        }

        return false;
    }

    public function search(SearchRequest $request): Page
    {
        return $this->store->allOrders($request);
    }

    private function assignItemIds(Order $order): void
    {
        foreach ($order->items as $item) {
            if ($item->id === null) {
                $item->persisted(Uuid::uuid4()->toString(), (string) $order->id);
            }
        }
    }
}
