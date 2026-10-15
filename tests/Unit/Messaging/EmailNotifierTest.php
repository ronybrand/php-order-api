<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging;

use App\Domain\OrderStatus;
use App\Domain\OrderStatusChanged;
use App\Infrastructure\Messaging\EmailNotifier;
use App\Infrastructure\Messaging\Mailer;
use App\Infrastructure\Messaging\PermanentFailure;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EmailNotifierTest extends TestCase
{
    private RecordingMailer $mailer;

    private EmailNotifier $notifier;

    protected function setUp(): void
    {
        $this->mailer = new RecordingMailer();
        $this->notifier = new EmailNotifier($this->mailer);
    }

    private static function event(string $email = 'ana@example.com', string $name = 'Ana'): OrderStatusChanged
    {
        return new OrderStatusChanged(
            '0b9d3bfa-1d54-4f32-9d0e-6a8a4b7b6a11',
            $email,
            $name,
            OrderStatus::Open,
            OrderStatus::Confirmed,
            BigDecimal::of('10.30'),
            new DateTimeImmutable('2026-01-02T03:04:05Z'),
        );
    }

    public function test_renders_the_template(): void
    {
        $this->notifier->notify(self::event());

        self::assertSame('ana@example.com', $this->mailer->to);
        foreach (['0b9d3bfa-1d54-4f32-9d0e-6a8a4b7b6a11', 'Ana', 'CONFIRMED', '10.30'] as $expected) {
            self::assertStringContainsString($expected, $this->mailer->html);
        }
        self::assertStringContainsString('CONFIRMED', $this->mailer->subject);
    }

    public function test_escapes_the_customer_name(): void
    {
        $this->notifier->notify(self::event(name: '<script>alert(1)</script>'));

        self::assertStringNotContainsString('<script>', $this->mailer->html);
    }

    public function test_rejects_recipients_that_could_inject_headers(): void
    {
        foreach (["a@example.com\r\nBcc: evil@example.com", 'not-an-email', '', 'Ana <a@example.com>'] as $email) {
            try {
                $this->notifier->notify(self::event($email));
                self::fail("recipient {$email} should have been refused");
            } catch (PermanentFailure) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame('', $this->mailer->to, 'nothing may be sent to a refused recipient');
    }

    public function test_transport_errors_stay_retryable(): void
    {
        $this->mailer->failure = new RuntimeException('connection refused');

        try {
            $this->notifier->notify(self::event());
            self::fail('expected the transport error');
        } catch (RuntimeException $e) {
            self::assertNotInstanceOf(PermanentFailure::class, $e);
        }
    }
}

final class RecordingMailer implements Mailer
{
    public string $to = '';

    public string $subject = '';

    public string $html = '';

    public ?RuntimeException $failure = null;

    public function send(string $to, string $subject, string $html): void
    {
        $this->to = $to;
        $this->subject = $subject;
        $this->html = $html;
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
