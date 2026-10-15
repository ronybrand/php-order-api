<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\CustomerService;
use App\Application\EventPublisher;
use App\Application\HealthCheck;
use App\Application\OrderService;
use App\Application\Store;
use App\Auth\JwtVerifier;
use App\Config\Settings;
use App\Infrastructure\Postgres\PostgresHealth;
use App\Infrastructure\Postgres\PostgresStore;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class, static function (): Settings {
            /** @var array<string, string|null> $environment */
            $environment = array_map(
                static fn(mixed $value): ?string => is_scalar($value) ? (string) $value : null,
                (array) config('orderapi'),
            );

            return Settings::fromEnvironment($environment);
        });

        $this->app->singleton(ClockInterface::class, static fn(): ClockInterface => new NativeClock());
        $this->app->singleton(Store::class, static fn(Application $app): Store => new PostgresStore($app->make(ConnectionInterface::class)));
        $this->app->singleton(HealthCheck::class, static fn(Application $app): HealthCheck => new PostgresHealth(
            $app->make(ConnectionInterface::class),
            $app->make(LoggerInterface::class),
        ));
        $this->app->singleton(JwtVerifier::class, static function (Application $app): JwtVerifier {
            $settings = $app->make(Settings::class);

            return JwtVerifier::fromFile($settings->jwtPublicKeyPath, $settings->jwtAudience, $settings->jwtIssuer, $app->make(ClockInterface::class));
        });

        // The publisher is optional: without a broker the API still works, it just does not notify.
        $this->app->singleton(OrderService::class, static fn(Application $app): OrderService => new OrderService(
            $app->make(Store::class),
            $app->bound(EventPublisher::class) ? $app->make(EventPublisher::class) : null,
            $app->make(ClockInterface::class),
            $app->make(LoggerInterface::class),
        ));
        $this->app->singleton(CustomerService::class, static fn(Application $app): CustomerService => new CustomerService(
            $app->make(Store::class),
            $app->make(ClockInterface::class),
            $app->make(LoggerInterface::class),
        ));
    }

    public function boot(): void
    {
        // Fail fast: an unsafe production configuration stops the process at startup, not at the first request.
        $settings = $this->app->make(Settings::class);
        $settings->assertValid();
        if (!$this->app->runningInConsole()) {
            $settings->assertValidForApi();
        }
    }
}
