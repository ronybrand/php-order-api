<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

/** Sends one HTML e-mail. The SMTP implementation is SmtpMailer. */
interface Mailer
{
    /** @throws PermanentFailure when the message can never be sent; any other exception is retryable */
    public function send(string $to, string $subject, string $html): void;
}
