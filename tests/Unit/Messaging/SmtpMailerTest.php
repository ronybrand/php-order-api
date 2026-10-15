<?php

declare(strict_types=1);

namespace Tests\Unit\Messaging;

use App\Infrastructure\Messaging\PermanentFailure;
use App\Infrastructure\Messaging\SmtpMailer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SmtpMailerTest extends TestCase
{
    public function test_builds_an_html_message_with_the_required_headers(): void
    {
        $email = SmtpMailer::buildMessage('orders@example.com', 'ana@example.com', 'Order CONFIRMED', '<p>hi</p>');

        self::assertSame('orders@example.com', $email->getFrom()[0]->getAddress());
        self::assertSame('ana@example.com', $email->getTo()[0]->getAddress());
        self::assertSame('Order CONFIRMED', $email->getSubject());
        self::assertSame('<p>hi</p>', $email->getHtmlBody());
    }

    public function test_refuses_header_injection(): void
    {
        $cases = [
            'subject' => ['orders@example.com', 'ana@example.com', "hi\r\nBcc: evil@example.com"],
            'to' => ['orders@example.com', "ana@example.com\r\nBcc: evil@example.com", 'hi'],
            'from' => ["orders@example.com\nX: y", 'ana@example.com', 'hi'],
        ];
        foreach ($cases as $name => [$from, $to, $subject]) {
            try {
                SmtpMailer::buildMessage($from, $to, $subject, 'body');
                self::fail("{$name}: header injection accepted");
            } catch (PermanentFailure) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_transport_requires_starttls_when_asked_to(): void
    {
        $strict = SmtpMailer::transport('smtp.example.com', 587, 'user', 'secret', requireTls: true);
        $relaxed = SmtpMailer::transport('localhost', 1025, '', '', requireTls: false);

        self::assertTrue($strict->isTlsRequired());
        self::assertTrue($strict->isAutoTls());
        self::assertFalse($relaxed->isTlsRequired());
    }

    public function test_rejects_a_bad_configuration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SmtpMailer('', 587, '', '', 'orders@example.com', false);
    }

    public function test_rejects_a_sender_that_is_not_a_bare_address(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SmtpMailer('smtp.example.com', 587, '', '', 'Orders <orders@example.com>', false);
    }
}
