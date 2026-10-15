<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Page;
use App\Domain\Customer;
use App\Domain\Order;
use Brick\Math\BigDecimal;

/**
 * The JSON contract of the API (snake_case). It is separate from the domain types on purpose: the contract must
 * not change because a domain class did. Money leaves as a JSON number rounded to two decimals, never as text.
 */
final class Representations
{
    /**
     * The customer exposes only id and name: tax id, passport and e-mail are PII and no consumer of this API
     * needs them back (the caller that created the record already has them).
     *
     * @return array{id: string|null, name: string}
     */
    public static function customer(Customer $customer): array
    {
        return ['id' => $customer->id, 'name' => $customer->name];
    }

    /** @return array<string, mixed> */
    public static function order(Order $order): array
    {
        return [
            'id' => $order->id,
            'customer_id' => $order->customerId,
            'status' => $order->status->value,
            'version' => $order->version,
            'items' => array_map(static fn($item): array => [
                'id' => $item->id,
                'order_id' => $item->orderId,
                'description' => $item->description,
                'unit_price' => self::money($item->unitPrice),
                'quantity' => $item->quantity,
                'subtotal' => self::money($item->subtotal()),
            ], $order->items),
            'total' => self::money($order->total()),
        ];
    }

    /**
     * @template T
     *
     * @param Page<T> $page
     * @param callable(T): array<string, mixed> $represent
     * @return array{items: list<array<string, mixed>>, total: int, page: int, size: int}
     */
    public static function page(Page $page, callable $represent): array
    {
        return [
            'items' => array_map($represent, $page->items),
            'total' => $page->total,
            'page' => $page->page,
            'size' => $page->size,
        ];
    }

    private static function money(BigDecimal $amount): float
    {
        return $amount->toScale(2)->toFloat();
    }
}
