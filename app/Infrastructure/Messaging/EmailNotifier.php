<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Domain\OrderStatusChanged;
use DateTimeZone;

/** Renders the status e-mail and hands it to a Mailer. */
final readonly class EmailNotifier implements Notifier
{
    public function __construct(private Mailer $mailer) {}

    public function notify(OrderStatusChanged $event): void
    {
        // A bad recipient never becomes valid by retrying.
        if (!self::validRecipient($event->customerEmail)) {
            throw new PermanentFailure('invalid recipient');
        }
        $this->mailer->send($event->customerEmail, 'Order ' . $event->newStatus->value, $this->render($event));
    }

    /** Every interpolated value is escaped for its HTML context: a customer named "<script>" injects nothing. */
    private function render(OrderStatusChanged $event): string
    {
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $when = $event->changedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i T');

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <body style="font-family: sans-serif">
              <p>Hello, {$escape($event->customerName)}.</p>
              <p>Your order <strong>{$escape($event->orderId)}</strong> changed from <strong>{$escape($event->oldStatus->value)}</strong> to <strong>{$escape($event->newStatus->value)}</strong>.</p>
              <p>Total: {$escape($event->totalAmount->toScale(2)->__toString())}</p>
              <p>Updated at {$escape($when)}.</p>
            </body>
            </html>
            HTML;
    }

    /** Only a bare address: control characters would allow header injection and a display name has no place here. */
    private static function validRecipient(string $email): bool
    {
        return $email !== ''
            && preg_match('/[\r\n]/', $email) !== 1
            && filter_var($email, FILTER_VALIDATE_EMAIL) === $email;
    }
}
