<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use InvalidArgumentException;
use SensitiveParameter;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface as MimeException;
use Throwable;
use RuntimeException;

/** Sends mail through an SMTP server with Symfony Mailer. */
final readonly class SmtpMailer implements Mailer
{
    private SymfonyMailer $mailer;

    public function __construct(
        string $host,
        int $port,
        string $user,
        #[SensitiveParameter]
        string $password,
        private string $from,
        bool $requireTls,
    ) {
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('smtp host and a valid port are required');
        }
        if (filter_var($from, FILTER_VALIDATE_EMAIL) !== $from) {
            throw new InvalidArgumentException('smtp sender must be a bare e-mail address');
        }
        $this->mailer = new SymfonyMailer(self::transport($host, $port, $user, $password, $requireTls));
    }

    /**
     * With requireTls the mail is not sent when the server does not offer STARTTLS, instead of going out (and the
     * credentials with it) in clear text. Production sets it.
     */
    public static function transport(string $host, int $port, string $user, #[SensitiveParameter] string $password, bool $requireTls): EsmtpTransport
    {
        $transport = new EsmtpTransport($host, $port);
        $transport->setAutoTls(true);
        $transport->setRequireTls($requireTls);
        if ($user !== '') {
            $transport->setUsername($user);
            $transport->setPassword($password);
        }

        return $transport;
    }

    /**
     * Builds the message. Header values with CR or LF are refused: they are how a header injection adds a Bcc.
     *
     * @throws PermanentFailure
     */
    public static function buildMessage(string $from, string $to, string $subject, string $html): Email
    {
        foreach (['from' => $from, 'to' => $to, 'subject' => $subject] as $name => $value) {
            if (preg_match('/[\r\n]/', $value) === 1) {
                throw new PermanentFailure("invalid {$name} header");
            }
        }
        try {
            return (new Email())->from($from)->to($to)->subject($subject)->html($html);
        } catch (MimeException) {
            throw new PermanentFailure('invalid address');
        }
    }

    public function send(string $to, string $subject, string $html): void
    {
        $message = self::buildMessage($this->from, $to, $subject, $html);
        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $e) {
            // Retryable. The transport message can name the server; it never carries the message content.
            throw new RuntimeException('smtp transport failure: ' . $e::class, previous: $e);
        } catch (Throwable $e) {
            throw new RuntimeException('smtp failure: ' . $e::class, previous: $e);
        }
    }
}
