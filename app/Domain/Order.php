<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Error\ApiException;
use App\Domain\Error\ErrorCode;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/** The aggregate root: it owns its items and keeps the total consistent with them. */
final class Order
{
    public const int MAX_ITEMS = 200;
    public const int MAX_ITEM_DESCRIPTION = 255;
    public const int MAX_QUANTITY = 10_000;
    private const int MONEY_SCALE = 2;
    private const string MAX_UNIT_PRICE = '99999999.99';

    /** @param list<Item> $items */
    private function __construct(
        public private(set) ?string $id,
        public readonly string $customerId,
        public private(set) OrderStatus $status,
        /** Optimistic-concurrency token: a write must carry the version it read (CONFLICT-00 otherwise). */
        public private(set) int $version,
        public private(set) array $items,
        public readonly DateTimeImmutable $createdAt,
        public private(set) DateTimeImmutable $updatedAt,
        public readonly string $createdBy,
        public private(set) string $updatedBy,
    ) {}

    /**
     * Builds an OPEN order from the requested items.
     *
     * @param list<ItemInput> $items
     */
    public static function open(string $customerId, array $items, string $actor, DateTimeImmutable $now): self
    {
        if (count($items) > self::MAX_ITEMS) {
            throw self::itemLimit();
        }
        $built = array_map(static function (ItemInput $input): Item {
            self::validate($input);

            return new Item(null, null, $input->description, $input->unitPrice, $input->quantity);
        }, $items);

        return new self(null, $customerId, OrderStatus::Open, 1, $built, $now, $now, $actor, $actor);
    }

    /**
     * Rebuilds a stored order: the data already passed validation when it was written.
     *
     * @param list<Item> $items
     */
    public static function reconstitute(
        string $id,
        string $customerId,
        OrderStatus $status,
        int $version,
        array $items,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        string $createdBy,
        string $updatedBy,
    ): self {
        return new self($id, $customerId, $status, $version, $items, $createdAt, $updatedAt, $createdBy, $updatedBy);
    }

    /** Derived from the current items on every call: there is no stored total to go stale. */
    public function total(): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($this->items as $item) {
            $total = $total->plus($item->subtotal());
        }

        return $total->toScale(self::MONEY_SCALE);
    }

    public function isEditable(): bool
    {
        return $this->status === OrderStatus::Open;
    }

    public function addItem(ItemInput $input, string $actor, DateTimeImmutable $now): void
    {
        $this->requireEditable();
        if (count($this->items) >= self::MAX_ITEMS) {
            throw self::itemLimit();
        }
        self::validate($input);
        $this->items[] = new Item(null, $this->id, $input->description, $input->unitPrice, $input->quantity);
        $this->touch($actor, $now);
    }

    public function updateItemQuantity(string $itemId, int $quantity, string $actor, DateTimeImmutable $now): void
    {
        $this->requireEditable();
        $item = $this->itemOrFail($itemId);
        self::validateQuantity($quantity);
        $item->changeQuantity($quantity);
        $this->touch($actor, $now);
    }

    public function removeItem(string $itemId, string $actor, DateTimeImmutable $now): void
    {
        $this->requireEditable();
        $this->itemOrFail($itemId);
        $this->items = array_values(array_filter($this->items, static fn(Item $i): bool => $i->id !== $itemId));
        $this->touch($actor, $now);
    }

    /** OPEN -> CONFIRMED. Returns the previous status. */
    public function confirm(string $actor, DateTimeImmutable $now): OrderStatus
    {
        if ($this->status !== OrderStatus::Open) {
            throw ApiException::validation(
                ErrorCode::InvalidTransition,
                'cannot confirm an order that is not OPEN',
                ['id' => $this->id, 'status' => $this->status->value],
            );
        }
        if ($this->items === []) {
            throw ApiException::validation(ErrorCode::OrderEmpty, 'an order without items cannot be confirmed', ['id' => $this->id]);
        }

        return $this->transitionTo(OrderStatus::Confirmed, $actor, $now);
    }

    /** OPEN|CONFIRMED -> CANCELED. Returns the previous status. */
    public function cancel(string $actor, DateTimeImmutable $now): OrderStatus
    {
        if ($this->status === OrderStatus::Canceled) {
            throw ApiException::validation(
                ErrorCode::AlreadyCanceled,
                'order is already canceled',
                ['id' => $this->id, 'status' => $this->status->value],
            );
        }

        return $this->transitionTo(OrderStatus::Canceled, $actor, $now);
    }

    public function persisted(string $id): void
    {
        $this->id = $id;
    }

    public function versionIncremented(): void
    {
        ++$this->version;
    }

    private function transitionTo(OrderStatus $to, string $actor, DateTimeImmutable $now): OrderStatus
    {
        $from = $this->status;
        $this->status = $to;
        $this->touch($actor, $now);

        return $from;
    }

    private function touch(string $actor, DateTimeImmutable $now): void
    {
        $this->updatedBy = $actor;
        $this->updatedAt = $now;
    }

    private function requireEditable(): void
    {
        if (!$this->isEditable()) {
            throw ApiException::validation(
                ErrorCode::OrderNotEditable,
                'order is not editable in its current status',
                ['id' => $this->id, 'status' => $this->status->value],
            );
        }
    }

    private function itemOrFail(string $itemId): Item
    {
        foreach ($this->items as $item) {
            if ($item->id === $itemId) {
                return $item;
            }
        }

        throw ApiException::notFound(
            ErrorCode::ItemNotFound,
            'item not found in this order',
            ['order_id' => $this->id, 'item_id' => $itemId],
        );
    }

    private static function validate(ItemInput $input): void
    {
        if (trim($input->description) === '') {
            throw ApiException::invalidField('description', 'description must not be blank');
        }
        if (mb_strlen($input->description) > self::MAX_ITEM_DESCRIPTION) {
            throw ApiException::invalidField('description', 'description is too long');
        }
        if (!$input->unitPrice->isPositive()) {
            throw ApiException::invalidField('unit_price', 'unit_price must be greater than zero');
        }
        if ($input->unitPrice->isGreaterThan(self::MAX_UNIT_PRICE)) {
            throw ApiException::invalidField('unit_price', 'unit_price is too large');
        }
        if ($input->unitPrice->strippedOfTrailingZeros()->getScale() > self::MONEY_SCALE) {
            throw ApiException::invalidField('unit_price', 'unit_price must have at most 2 decimal places');
        }
        self::validateQuantity($input->quantity);
    }

    private static function validateQuantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw ApiException::invalidField('quantity', 'quantity must be between 1 and 10000');
        }
    }

    private static function itemLimit(): ApiException
    {
        return ApiException::validation(ErrorCode::ItemLimit, 'an order can have at most 200 items', ['max' => self::MAX_ITEMS]);
    }
}
