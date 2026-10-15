<?php

declare(strict_types=1);

namespace App\Domain;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Fired at the end of confirm and cancel, only when the customer has an e-mail. The array form is the wire
 * contract of the notification queue, shared by the API (producer) and the worker (consumer).
 */
final readonly class OrderStatusChanged
{
    public function __construct(
        public string $orderId,
        public string $customerEmail,
        public string $customerName,
        public OrderStatus $oldStatus,
        public OrderStatus $newStatus,
        public BigDecimal $totalAmount,
        public DateTimeImmutable $changedAt,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'customer_email' => $this->customerEmail,
            'customer_name' => $this->customerName,
            'old_status' => $this->oldStatus->value,
            'new_status' => $this->newStatus->value,
            'total_amount' => (string) $this->totalAmount,
            'changed_at' => $this->changedAt->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidArgumentException when the payload is not a well-formed event
     */
    public static function fromArray(array $data): self
    {
        foreach (['order_id', 'customer_email', 'customer_name', 'old_status', 'new_status', 'total_amount', 'changed_at'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new InvalidArgumentException("missing or invalid field: {$key}");
            }
        }

        try {
            return new self(
                $data['order_id'],
                $data['customer_email'],
                $data['customer_name'],
                OrderStatus::from($data['old_status']),
                OrderStatus::from($data['new_status']),
                BigDecimal::of($data['total_amount']),
                new DateTimeImmutable($data['changed_at']),
            );
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('malformed event payload', previous: $e);
        }
    }

    /** Keeps the customer's e-mail and name out of logs and dumps: the order id is enough to trace the event. */
    public function __debugInfo(): array
    {
        return ['orderId' => $this->orderId, 'oldStatus' => $this->oldStatus, 'newStatus' => $this->newStatus];
    }
}
