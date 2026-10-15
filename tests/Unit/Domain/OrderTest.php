<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Error\ApiException;
use App\Domain\Error\ErrorCode;
use App\Domain\Item;
use App\Domain\ItemInput;
use App\Domain\Order;
use App\Domain\OrderStatus;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class OrderTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-14T12:00:00Z');
    }

    private static function item(string $price = '1', int $quantity = 1, string $description = 'Widget'): ItemInput
    {
        return new ItemInput($description, BigDecimal::of($price), $quantity);
    }

    /** An OPEN order whose items already have ids, as if loaded from the database. */
    private function openOrder(ItemInput ...$inputs): Order
    {
        $order = Order::open(Uuid::uuid4()->toString(), array_values($inputs), 'user-1', $this->now);

        return Order::reconstitute(
            id: Uuid::uuid4()->toString(),
            customerId: $order->customerId,
            status: OrderStatus::Open,
            version: 1,
            items: array_map(
                static fn(Item $i): Item => new Item(Uuid::uuid4()->toString(), null, $i->description, $i->unitPrice, $i->quantity),
                $order->items,
            ),
            createdAt: $this->now,
            updatedAt: $this->now,
            createdBy: 'user-1',
            updatedBy: 'user-1',
        );
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

    public function test_builds_an_open_order_with_the_derived_total(): void
    {
        $order = $this->openOrder(self::item('2.50', 4), self::item('10', 1));

        self::assertSame(OrderStatus::Open, $order->status);
        self::assertSame(1, $order->version);
        self::assertCount(2, $order->items);
        self::assertTrue($order->total()->isEqualTo('20'));
    }

    public function test_the_total_is_always_derived_from_the_current_items(): void
    {
        $order = $this->openOrder(self::item('10', 2));

        $order->addItem(self::item('0.10', 3), 'u', $this->now);
        self::assertTrue($order->total()->isEqualTo('20.30'));

        $order->updateItemQuantity((string) $order->items[0]->id, 1, 'u', $this->now);
        self::assertTrue($order->total()->isEqualTo('10.30'));

        $order->removeItem((string) $order->items[0]->id, 'u', $this->now);
        self::assertTrue($order->total()->isEqualTo('0.30'));
    }

    public function test_the_total_does_not_lose_cents(): void
    {
        // 0.1 + 0.2 in binary floating point is 0.30000000000000004.
        $order = $this->openOrder(self::item('0.10'), self::item('0.20'));

        self::assertSame('0.30', (string) $order->total());
    }

    /** @return array<string, array{ItemInput}> */
    public static function invalidItems(): array
    {
        return [
            'blank description' => [self::item(description: '  ')],
            'long description' => [self::item(description: str_repeat('a', 256))],
            'zero price' => [self::item('0')],
            'negative price' => [self::item('-1')],
            'three decimals' => [self::item('1.001')],
            'price above column' => [self::item('100000000')],
            'zero quantity' => [self::item(quantity: 0)],
            'negative quantity' => [self::item(quantity: -3)],
            'quantity above max' => [self::item(quantity: Order::MAX_QUANTITY + 1)],
        ];
    }

    #[DataProvider('invalidItems')]
    public function test_rejects_invalid_items(ItemInput $input): void
    {
        self::assertApiError(ErrorCode::InvalidRequest, fn() => Order::open(Uuid::uuid4()->toString(), [$input], 'u', $this->now));
    }

    public function test_the_item_limit_is_exact(): void
    {
        $atLimit = array_fill(0, Order::MAX_ITEMS, self::item());

        $order = Order::open(Uuid::uuid4()->toString(), $atLimit, 'u', $this->now);

        self::assertCount(Order::MAX_ITEMS, $order->items);
        self::assertApiError(
            ErrorCode::ItemLimit,
            fn() => Order::open(Uuid::uuid4()->toString(), [...$atLimit, self::item()], 'u', $this->now),
        );
    }

    public function test_adding_an_item_respects_the_limit_too(): void
    {
        $order = $this->openOrder(...array_fill(0, Order::MAX_ITEMS, self::item()));

        self::assertApiError(ErrorCode::ItemLimit, fn() => $order->addItem(self::item(), 'u', $this->now));
    }

    /** @return array<string, array{OrderStatus}> */
    public static function nonOpenStatuses(): array
    {
        return ['confirmed' => [OrderStatus::Confirmed], 'canceled' => [OrderStatus::Canceled]];
    }

    #[DataProvider('nonOpenStatuses')]
    public function test_items_are_editable_only_while_open(OrderStatus $status): void
    {
        $order = $this->openOrder(self::item());
        $itemId = (string) $order->items[0]->id;
        $order = Order::reconstitute(
            (string) $order->id,
            $order->customerId,
            $status,
            1,
            $order->items,
            $this->now,
            $this->now,
            'u',
            'u',
        );

        self::assertApiError(ErrorCode::OrderNotEditable, fn() => $order->addItem(self::item(), 'u', $this->now));
        self::assertApiError(ErrorCode::OrderNotEditable, fn() => $order->updateItemQuantity($itemId, 2, 'u', $this->now));
        self::assertApiError(ErrorCode::OrderNotEditable, fn() => $order->removeItem($itemId, 'u', $this->now));
        self::assertCount(1, $order->items);
        self::assertSame(1, $order->items[0]->quantity);
    }

    public function test_item_operations_on_an_unknown_item(): void
    {
        $order = $this->openOrder(self::item());
        $unknown = Uuid::uuid4()->toString();

        self::assertApiError(ErrorCode::ItemNotFound, fn() => $order->updateItemQuantity($unknown, 2, 'u', $this->now));
        self::assertApiError(ErrorCode::ItemNotFound, fn() => $order->removeItem($unknown, 'u', $this->now));
    }

    public function test_updating_the_quantity_validates_it(): void
    {
        $order = $this->openOrder(self::item());
        $itemId = (string) $order->items[0]->id;

        self::assertApiError(ErrorCode::InvalidRequest, fn() => $order->updateItemQuantity($itemId, 0, 'u', $this->now));
        self::assertApiError(ErrorCode::InvalidRequest, fn() => $order->updateItemQuantity($itemId, Order::MAX_QUANTITY + 1, 'u', $this->now));

        $order->updateItemQuantity($itemId, Order::MAX_QUANTITY, 'u', $this->now);
        self::assertSame(Order::MAX_QUANTITY, $order->items[0]->quantity);
    }

    public function test_confirm_moves_open_to_confirmed_and_reports_the_previous_status(): void
    {
        $order = $this->openOrder(self::item());

        $previous = $order->confirm('approver', $this->now->modify('+1 minute'));

        self::assertSame(OrderStatus::Open, $previous);
        self::assertSame(OrderStatus::Confirmed, $order->status);
        self::assertSame('approver', $order->updatedBy);
    }

    public function test_an_empty_order_cannot_be_confirmed(): void
    {
        $order = $this->openOrder();

        self::assertApiError(ErrorCode::OrderEmpty, fn() => $order->confirm('u', $this->now));
        self::assertSame(OrderStatus::Open, $order->status);
    }

    #[DataProvider('nonOpenStatuses')]
    public function test_confirm_is_only_allowed_from_open(OrderStatus $from): void
    {
        $order = $this->orderIn($from);

        self::assertApiError(ErrorCode::InvalidTransition, fn() => $order->confirm('u', $this->now));
    }

    /** @return array<string, array{OrderStatus}> */
    public static function cancelableStatuses(): array
    {
        return ['open' => [OrderStatus::Open], 'confirmed' => [OrderStatus::Confirmed]];
    }

    #[DataProvider('cancelableStatuses')]
    public function test_cancel_from_open_or_confirmed(OrderStatus $from): void
    {
        $order = $this->orderIn($from);

        $previous = $order->cancel('u', $this->now);

        self::assertSame($from, $previous);
        self::assertSame(OrderStatus::Canceled, $order->status);
    }

    public function test_an_already_canceled_order_cannot_be_canceled_again(): void
    {
        $order = $this->orderIn(OrderStatus::Canceled);

        self::assertApiError(ErrorCode::AlreadyCanceled, fn() => $order->cancel('u', $this->now));
    }

    private function orderIn(OrderStatus $status): Order
    {
        $order = $this->openOrder(self::item());

        return Order::reconstitute(
            (string) $order->id,
            $order->customerId,
            $status,
            1,
            $order->items,
            $this->now,
            $this->now,
            'u',
            'u',
        );
    }
}
