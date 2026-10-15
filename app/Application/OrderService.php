<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Error\ApiException;
use App\Domain\Error\ErrorCode;
use App\Domain\ItemInput;
use App\Domain\Order;
use App\Domain\OrderStatus;
use App\Domain\OrderStatusChanged;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/** The order use cases. */
final readonly class OrderService
{
    /** The publisher may be null when no notification is wanted. */
    public function __construct(
        private Store $store,
        private ?EventPublisher $publisher,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    /**
     * Opens an order for an existing customer (VALIDATION-07 if the customer does not exist).
     *
     * @param list<ItemInput> $items
     */
    public function create(string $customerId, array $items, Actor $actor): Order
    {
        $order = $this->store->inTransaction(function (Repositories $repos) use ($customerId, $items, $actor): Order {
            // FOR SHARE on the customer, in the same transaction that saves the order: a concurrent delete of this
            // customer waits for us (or we wait for it and then find nothing), so an order is never created under
            // a customer that was deleted in between.
            try {
                $repos->customers->lockForShare($customerId);
            } catch (ApiException $e) {
                if ($e->errorCode === ErrorCode::CustomerNotFound) {
                    throw ApiException::validation(ErrorCode::CustomerNotFoundInBody, 'customer does not exist', ['customer_id' => $customerId]);
                }
                throw $e;
            }
            $order = Order::open($customerId, $items, $actor->id, $this->clock->now());
            $repos->orders->create($order);

            return $order;
        });
        $this->logger->info('order created', ['order_id' => $order->id, 'actor' => $actor->id]);

        return $order;
    }

    public function get(string $id): Order
    {
        return $this->store->repos()->orders->get($id);
    }

    public function delete(string $id, Actor $actor): void
    {
        $orders = $this->store->repos()->orders;
        $orders->get($id);
        $orders->softDelete($id, $actor->id, $this->clock->now());
        $this->logger->info('order deleted', ['order_id' => $id, 'actor' => $actor->id]);
    }

    public function addItem(string $orderId, ItemInput $input, Actor $actor): Order
    {
        return $this->mutate($orderId, fn(Order $o) => $o->addItem($input, $actor->id, $this->clock->now()));
    }

    public function updateItemQuantity(string $orderId, string $itemId, int $quantity, Actor $actor): Order
    {
        return $this->mutate($orderId, fn(Order $o) => $o->updateItemQuantity($itemId, $quantity, $actor->id, $this->clock->now()));
    }

    public function removeItem(string $orderId, string $itemId, Actor $actor): Order
    {
        return $this->mutate($orderId, fn(Order $o) => $o->removeItem($itemId, $actor->id, $this->clock->now()));
    }

    /** OPEN -> CONFIRMED, and announces the change. */
    public function confirm(string $orderId, Actor $actor): Order
    {
        return $this->transition($orderId, fn(Order $o): OrderStatus => $o->confirm($actor->id, $this->clock->now()));
    }

    /** OPEN|CONFIRMED -> CANCELED, and announces the change. */
    public function cancel(string $orderId, Actor $actor): Order
    {
        return $this->transition($orderId, fn(Order $o): OrderStatus => $o->cancel($actor->id, $this->clock->now()));
    }

    /** @return Page<Order> */
    public function search(SearchRequest $request): Page
    {
        $request = $request->withDefaults();
        $request->validate();

        return $this->store->repos()->orders->search($request);
    }

    /**
     * Loads the order, applies the change and saves it with the version it read.
     *
     * @param callable(Order): mixed $change
     */
    private function mutate(string $orderId, callable $change): Order
    {
        return $this->store->inTransaction(function (Repositories $repos) use ($orderId, $change): Order {
            $order = $repos->orders->get($orderId);
            $change($order);
            $repos->orders->save($order);

            return $order;
        });
    }

    /** @param callable(Order): OrderStatus $change */
    private function transition(string $orderId, callable $change): Order
    {
        $previous = null;
        $order = $this->mutate($orderId, function (Order $order) use ($change, &$previous): void {
            $previous = $change($order);
        });
        \assert($previous instanceof OrderStatus);
        $this->logger->info('order status changed', ['order_id' => $order->id, 'from' => $previous->value, 'to' => $order->status->value]);
        $this->announce($order, $previous);

        return $order;
    }

    /**
     * Publishes the status change after the commit. A broker failure is logged and never reaches the caller: the
     * transition already happened and must not fail because a notification could not be queued.
     */
    private function announce(Order $order, OrderStatus $previous): void
    {
        if ($this->publisher === null) {
            return;
        }
        try {
            $customer = $this->store->repos()->customers->get($order->customerId);
            if ($customer->email === '') {
                return;
            }
            $this->publisher->publishStatusChanged(new OrderStatusChanged(
                (string) $order->id,
                $customer->email,
                $customer->name,
                $previous,
                $order->status,
                $order->total(),
                $this->clock->now(),
            ));
        } catch (Throwable $e) {
            // The exception message may carry connection details, never the customer's data.
            $this->logger->error('failed to announce order status change', [
                'order_id' => $order->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
