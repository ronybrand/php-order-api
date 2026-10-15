<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Config\InvalidConfiguration;
use App\Console\StopSignal;
use App\Config\Settings;
use App\Infrastructure\Messaging\AmqpConsumer;
use App\Infrastructure\Messaging\EmailNotifier;
use App\Infrastructure\Messaging\Processor;
use App\Infrastructure\Messaging\SeenSet;
use App\Infrastructure\Messaging\SmtpMailer;
use Illuminate\Console\Command;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Throwable;

/** The notification worker: consumes order.status.changed and sends the status e-mails. */
final class OrdersWork extends Command implements SignalableCommandInterface
{
    private const int RECONNECT_DELAY_SECONDS = 5;

    protected $signature = 'orders:work';

    protected $description = 'Consume order status changes from RabbitMQ and send the notification e-mails';

    private readonly StopSignal $stop;

    public function __construct()
    {
        parent::__construct();
        $this->stop = new StopSignal();
    }

    public function handle(Settings $settings, ClockInterface $clock, LoggerInterface $logger): int
    {
        try {
            $settings->assertValid();
            $settings->assertValidForWorker();
        } catch (InvalidConfiguration $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $mailer = new SmtpMailer(
            $settings->smtpHost,
            $settings->smtpPort,
            $settings->smtpUser,
            $settings->smtpPassword(),
            $settings->smtpFrom,
            $settings->smtpRequireTls(),
        );
        $processor = new Processor(new EmailNotifier($mailer), new SeenSet($clock), $logger);
        $consumer = new AmqpConsumer($settings->brokerUrl, $processor, $logger);

        while (!$this->stop->requested()) {
            try {
                $consumer->consume($this->stop->requested(...));
            } catch (Throwable $e) {
                // The broker went away: log and reconnect. The exception class is enough, URLs can carry credentials.
                $logger->error('consumer stopped, reconnecting', ['exception' => $e::class]);
                $this->stop->sleep(self::RECONNECT_DELAY_SECONDS);
            }
        }

        return self::SUCCESS;
    }

    /** @return list<int> SIGINT and SIGTERM, only where the pcntl extension exists */
    public function getSubscribedSignals(): array
    {
        return extension_loaded('pcntl') ? [2, 15] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stop->request();

        return false;
    }
}
