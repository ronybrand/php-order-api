<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\InvalidConfiguration;
use App\Config\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    /** @return array<string, string> */
    private static function production(): array
    {
        return [
            'APP_ENV' => 'production',
            'JWT_AUDIENCE' => 'order-api',
            'JWT_ISSUER' => 'https://idp.example',
            'CORS_ORIGINS' => 'https://app.example',
            'BROKER_URL' => 'amqps://broker',
        ];
    }

    public function test_development_defaults(): void
    {
        $settings = Settings::fromEnvironment([]);

        self::assertFalse($settings->isProduction());
        self::assertSame(1_048_576, $settings->maxBodyBytes);
        self::assertSame(120, $settings->rateLimitPerUser);
        self::assertSame(30, $settings->rateLimitWrites);
        self::assertSame(600, $settings->rateLimitPerIp);
        self::assertSame([], $settings->corsOrigins);
    }

    public function test_a_valid_production_configuration_passes(): void
    {
        $settings = Settings::fromEnvironment(self::production());

        self::assertTrue($settings->isProduction());
        $settings->assertValid();
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function badProductionConfigurations(): array
    {
        $with = static fn(string $key, string $value): array => [$key => $value] + self::production();

        return [
            'no audience' => [$with('JWT_AUDIENCE', ''), 'JWT_AUDIENCE'],
            'no issuer' => [$with('JWT_ISSUER', ''), 'JWT_ISSUER'],
            'no cors origins' => [$with('CORS_ORIGINS', ''), 'CORS_ORIGINS'],
            'wildcard cors' => [$with('CORS_ORIGINS', '*'), 'CORS_ORIGINS'],
            'plain amqp broker' => [$with('BROKER_URL', 'amqp://broker'), 'amqps'],
            'unknown environment' => [$with('APP_ENV', 'staging'), 'APP_ENV'],
        ];
    }

    /** @param array<string, string> $environment */
    #[DataProvider('badProductionConfigurations')]
    public function test_production_fails_fast_on_an_unsafe_configuration(array $environment, string $mentions): void
    {
        try {
            Settings::fromEnvironment($environment)->assertValid();
            self::fail('expected the configuration to be refused');
        } catch (InvalidConfiguration $e) {
            self::assertStringContainsString($mentions, $e->getMessage());
        }
    }

    public function test_a_malformed_integer_is_refused(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('RATE_LIMIT_PER_USER');

        Settings::fromEnvironment(['RATE_LIMIT_PER_USER' => 'abc']);
    }

    public function test_parses_the_cors_origin_list(): void
    {
        $settings = Settings::fromEnvironment(['CORS_ORIGINS' => ' https://a.example , https://b.example,']);

        self::assertSame(['https://a.example', 'https://b.example'], $settings->corsOrigins);
    }

    public function test_the_api_needs_a_key_audience_issuer_and_positive_limits(): void
    {
        $this->expectException(InvalidConfiguration::class);

        Settings::fromEnvironment([])->assertValidForApi();
    }

    public function test_the_worker_needs_a_broker_and_smtp(): void
    {
        $this->expectException(InvalidConfiguration::class);

        Settings::fromEnvironment([])->assertValidForWorker();
    }

    public function test_smtp_requires_starttls_in_production_only(): void
    {
        self::assertTrue(Settings::fromEnvironment(self::production())->smtpRequireTls());
        self::assertFalse(Settings::fromEnvironment([])->smtpRequireTls());
    }
}
