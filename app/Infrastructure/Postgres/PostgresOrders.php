<?php

declare(strict_types=1);

namespace App\Infrastructure\Postgres;

use App\Application\OrderRepository;
use App\Application\Page;
use App\Application\SearchRequest;
use App\Domain\Error\ApiException;
use App\Domain\Error\ErrorCode;
use App\Domain\Item;
use App\Domain\Order;
use App\Domain\OrderStatus;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use stdClass;

final readonly class PostgresOrders implements OrderRepository
{
    private const string COLUMNS = 'id, customer_id, status, version, created_at, updated_at, created_by, updated_by';

    public function __construct(
        private ConnectionInterface $db,
        private SearchQueryBuilder $searches = new SearchQueryBuilder(),
    ) {}

    public function get(string $id): Order
    {
        $row = Rows::isUuid($id)
            ? $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM orders WHERE id = ? AND deleted_at IS NULL', [$id])
            : null;
        if (!$row instanceof stdClass) {
            throw self::notFound($id);
        }

        return self::hydrate($row, $this->itemsOf([Rows::string($row, 'id')]));
    }

    public function create(Order $order): void
    {
        PostgresErrors::guard(fn() => $this->db->transaction(function () use ($order): void {
            $row = $this->db->selectOne(
                'INSERT INTO orders (customer_id, status, version, created_at, updated_at, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [
                    $order->customerId, $order->status->value, $order->version,
                    $order->createdAt->format('c'), $order->updatedAt->format('c'), $order->createdBy, $order->updatedBy,
                ],
            );
            \assert($row instanceof stdClass);
            $order->persisted(Rows::string($row, 'id'));
            foreach ($order->items as $item) {
                $this->insertItem($order, $item);
            }
        }));
    }

    public function save(Order $order): void
    {
        PostgresErrors::guard(fn() => $this->db->transaction(function () use ($order): void {
            $affected = $this->db->update(
                'UPDATE orders SET status = ?, updated_at = ?, updated_by = ?, version = version + 1
                  WHERE id = ? AND version = ? AND deleted_at IS NULL',
                [$order->status->value, $order->updatedAt->format('c'), $order->updatedBy, $order->id, $order->version],
            );
            if ($affected === 0) {
                throw PostgresErrors::concurrentModification();
            }
            $this->syncItems($order);
        }));
        $order->versionIncremented();
    }

    public function softDelete(string $id, string $actor, DateTimeImmutable $at): void
    {
        $affected = Rows::isUuid($id) ? PostgresErrors::guard(fn() => $this->db->update(
            'UPDATE orders
                SET deleted_at = ?, deleted_by = ?, updated_at = ?, updated_by = ?, version = version + 1
              WHERE id = ? AND deleted_at IS NULL',
            [$at->format('c'), $actor, $at->format('c'), $actor, $id],
        )) : 0;
        if ($affected === 0) {
            throw self::notFound($id);
        }
    }

    public function hasActive(string $customerId): bool
    {
        $row = $this->db->selectOne(
            'SELECT EXISTS (SELECT 1 FROM orders WHERE customer_id = ? AND deleted_at IS NULL) AS active',
            [$customerId],
        );
        \assert($row instanceof stdClass);

        return (bool) $row->active;
    }

    public function search(SearchRequest $request): Page
    {
        $query = $this->searches->build(Entities::orders(), $request);
        $total = $this->db->selectOne("SELECT count(*) AS total FROM orders WHERE {$query->where}", $query->bindings);
        \assert($total instanceof stdClass);

        $rows = array_values($this->db->select(
            'SELECT ' . self::COLUMNS . " FROM orders WHERE {$query->where} ORDER BY {$query->orderBy} LIMIT ? OFFSET ?",
            [...$query->bindings, $request->size, $request->offset()],
        ));
        $items = $this->itemsOf(array_map(static function (mixed $row): string {
            \assert($row instanceof stdClass);

            return Rows::string($row, 'id');
        }, $rows));

        return new Page(
            array_map(static function (mixed $row) use ($items): Order {
                \assert($row instanceof stdClass);

                return self::hydrate($row, $items);
            }, $rows),
            Rows::int($total, 'total'),
            $request->page,
            $request->size,
        );
    }

    private function insertItem(Order $order, Item $item): void
    {
        // The price travels as text and is cast by the server: no float ever touches money.
        $row = $this->db->selectOne(
            'INSERT INTO order_items (order_id, description, unit_price, quantity) VALUES (?, ?, ?::numeric, ?) RETURNING id',
            [$order->id, $item->description, (string) $item->unitPrice->toScale(2), $item->quantity],
        );
        \assert($row instanceof stdClass);
        $item->persisted(Rows::string($row, 'id'), (string) $order->id);
    }

    /**
     * Makes the stored items equal to the aggregate's: rows no longer in it are deleted, new ones are inserted and
     * the quantities of the existing ones are updated in one statement.
     */
    private function syncItems(Order $order): void
    {
        $kept = [];
        foreach ($order->items as $item) {
            if ($item->id !== null) {
                $kept[] = $item->id;
            }
        }
        $this->db->delete(
            'DELETE FROM order_items WHERE order_id = ? AND NOT (id = ANY(?::uuid[]))',
            [$order->id, '{' . implode(',', $kept) . '}'],
        );

        $existing = [];
        foreach ($order->items as $item) {
            if ($item->id === null) {
                $this->insertItem($order, $item);
            } else {
                $existing[] = $item;
            }
        }
        if ($existing === []) {
            return;
        }
        $values = implode(', ', array_fill(0, count($existing), '(?::uuid, ?::integer)'));
        $bindings = [];
        foreach ($existing as $item) {
            array_push($bindings, $item->id, $item->quantity);
        }
        $this->db->update(
            "UPDATE order_items AS i SET quantity = v.quantity FROM (VALUES {$values}) AS v(id, quantity)
              WHERE i.id = v.id AND i.order_id = ? AND i.quantity IS DISTINCT FROM v.quantity",
            [...$bindings, $order->id],
        );
    }

    /**
     * Loads the items of the given orders in insertion order, grouped by order.
     *
     * @param list<string> $orderIds
     * @return array<string, list<Item>>
     */
    private function itemsOf(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $rows = $this->db->select(
            'SELECT id, order_id, description, unit_price::text AS unit_price, quantity
               FROM order_items WHERE order_id = ANY(?::uuid[]) ORDER BY seq',
            ['{' . implode(',', $orderIds) . '}'],
        );
        $grouped = [];
        foreach ($rows as $row) {
            \assert($row instanceof stdClass);
            $orderId = Rows::string($row, 'order_id');
            $grouped[$orderId][] = new Item(
                Rows::string($row, 'id'),
                $orderId,
                Rows::string($row, 'description'),
                BigDecimal::of(Rows::string($row, 'unit_price')),
                Rows::int($row, 'quantity'),
            );
        }

        return $grouped;
    }

    /** @param array<string, list<Item>> $items */
    private static function hydrate(stdClass $row, array $items): Order
    {
        $id = Rows::string($row, 'id');

        return Order::reconstitute(
            $id,
            Rows::string($row, 'customer_id'),
            OrderStatus::from(Rows::string($row, 'status')),
            Rows::int($row, 'version'),
            $items[$id] ?? [],
            Rows::time($row, 'created_at'),
            Rows::time($row, 'updated_at'),
            Rows::string($row, 'created_by'),
            Rows::string($row, 'updated_by'),
        );
    }

    private static function notFound(string $id): ApiException
    {
        return ApiException::notFound(ErrorCode::OrderNotFound, 'order not found', ['id' => $id]);
    }
}
