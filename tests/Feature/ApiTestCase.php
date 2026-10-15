<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\EventPublisher;
use App\Application\HealthCheck;
use App\Application\Store;
use App\Auth\JwtVerifier;
use App\Config\Settings;
use Firebase\JWT\JWT;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Tests\Support\InMemoryStore;
use Tests\Support\RecordingPublisher;
use Tests\TestCase;

/**
 * Base of the HTTP tests: the real application (router, middleware, controllers, services) over an in-memory
 * store and real RS256 tokens. Only the database is replaced, so a test proves what runs in production.
 */
abstract class ApiTestCase extends TestCase
{
    protected const string AUDIENCE = 'order-api';
    protected const string ISSUER = 'https://idp.example';

    protected const string ORDER_ID = '00000000-0000-4000-8000-0000000000bb';
    protected const string ITEM_ID = '00000000-0000-4000-8000-0000000000aa';
    protected const string CUSTOMER_ID = '00000000-0000-4000-8000-0000000000cc';

    private static string $privateKey;

    private static string $publicKey;

    protected InMemoryStore $store;

    protected RecordingPublisher $publisher;

    protected MockClock $clock;

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key, 'openssl could not generate a key (is OPENSSL_CONF set?)');
        openssl_pkey_export($key, $private);
        self::assertIsString($private);
        self::$privateKey = $private;
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);
        self::$publicKey = $details['key'];
    }

    /** @param array<string, string> $overrides environment values (rate limits, CORS...) for this test */
    protected function configure(array $overrides = []): void
    {
        $this->store = new InMemoryStore();
        $this->publisher = new RecordingPublisher();
        $this->clock = new MockClock('2026-10-14 12:00:00');
        $environment = $overrides + [
            'APP_ENV' => 'testing',
            'RATE_LIMIT_PER_USER' => '1000',
            'RATE_LIMIT_WRITES' => '1000',
            'RATE_LIMIT_PER_IP' => '1000',
            'MAX_BODY_BYTES' => '4096',
            'CORS_ORIGINS' => 'https://app.example',
        ];
        $settings = Settings::fromEnvironment($environment);
        $this->app->instance(Settings::class, $settings);
        $this->app->instance(Store::class, $this->store);
        $this->app->instance(EventPublisher::class, $this->publisher);
        $this->app->instance(ClockInterface::class, $this->clock);
        $this->app->instance(HealthCheck::class, new class implements HealthCheck {
            public function isHealthy(): bool
            {
                return true;
            }
        });
        // The services were built (and cached) with the previous test's collaborators.
        $this->app->forgetInstance(\App\Application\OrderService::class);
        $this->app->forgetInstance(\App\Application\CustomerService::class);
        $this->app->instance(JwtVerifier::class, new JwtVerifier(self::$publicKey, self::AUDIENCE, self::ISSUER, $this->clock));
        // A fresh counter store per test: rate-limit state must not leak between tests.
        $this->app->forgetInstance(RateLimiter::class);
        Cache::flush();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure();
    }

    /** @param list<string> $roles */
    protected function token(string $subject = 'user-1', array $roles = ['user']): string
    {
        return JWT::encode([
            'sub' => $subject, 'aud' => self::AUDIENCE, 'iss' => self::ISSUER, 'roles' => $roles,
            'exp' => $this->clock->now()->getTimestamp() + 3600,
        ], self::$privateKey, 'RS256');
    }

    protected function user(): string
    {
        return $this->token('user-1', ['user']);
    }

    protected function admin(): string
    {
        return $this->token('admin-1', ['admin']);
    }

    /**
     * @param array<string, mixed>|string $body a string is sent raw, an array is encoded as JSON
     * @param array<string, string> $headers
     * @return TestResponse<\Illuminate\Http\Response>
     */
    protected function request(string $method, string $uri, ?string $token = null, array|string $body = '', array $headers = []): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $content = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body;

        return $this->call($method, $uri, [], [], [], $server, $content);
    }

    /**
     * @param TestResponse<\Illuminate\Http\Response> $response
     * @return array<string, mixed>
     */
    protected function decoded(TestResponse $response): array
    {
        $content = $response->getContent();
        $decoded = json_decode($content === false || $content === '' ? '[]' : $content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $result = [];
        foreach ($decoded as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    protected static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /** @param TestResponse<\Illuminate\Http\Response> $response */
    protected static function header(TestResponse $response, string $name): string
    {
        return $response->headers->get($name) ?? '';
    }
}
