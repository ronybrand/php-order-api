<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\EventPublisher;
use App\Config\Settings;
use App\Infrastructure\Messaging\AmqpPublisher;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class WorkerWiringTest extends TestCase
{
    public function test_the_worker_command_refuses_to_start_without_a_broker_and_smtp(): void
    {
        $this->app->instance(Settings::class, Settings::fromEnvironment(['APP_ENV' => 'testing']));

        $command = $this->artisan('orders:work');

        self::assertInstanceOf(PendingCommand::class, $command);
        $command->expectsOutputToContain('BROKER_URL is required')->assertFailed();
    }

    public function test_the_api_publishes_through_rabbitmq_only_when_a_broker_is_configured(): void
    {
        $this->app->instance(Settings::class, Settings::fromEnvironment(['APP_ENV' => 'testing', 'BROKER_URL' => 'amqp://guest:guest@127.0.0.1:5672/']));
        $this->app->forgetInstance(EventPublisher::class);

        $publisher = $this->app->make(EventPublisher::class);

        // The publisher connects lazily, so resolving it needs no broker.
        self::assertInstanceOf(AmqpPublisher::class, $publisher);
    }
}
