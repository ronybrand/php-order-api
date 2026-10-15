<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application\HealthCheck;
use App\Application\Store;
use App\Infrastructure\Postgres\PostgresHealth;
use App\Infrastructure\Postgres\PostgresStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Psr\Log\NullLogger;
use Tests\Feature\ApiTestCase;

/**
 * The whole stack over real HTTP semantics and a real PostgreSQL (DB_URL): router, middleware, auth, services,
 * repositories and the database. Skipped without DB_URL.
 */
final class EndToEndTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $url = getenv('DB_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('DB_URL not set');
        }
        self::assertSame(0, Artisan::call('migrate', ['--force' => true]));
        DB::connection()->unprepared('TRUNCATE order_items, orders, customers CASCADE');
        $this->app->instance(Store::class, new PostgresStore(DB::connection()));
        $this->app->instance(HealthCheck::class, new PostgresHealth(DB::connection(), new NullLogger()));
        $this->app->forgetInstance(\App\Application\OrderService::class);
        $this->app->forgetInstance(\App\Application\CustomerService::class);
    }

    private function createCustomer(string $taxId, string $email): string
    {
        $response = $this->request('POST', '/customers', $this->admin(), ['name' => 'Ana', 'tax_id' => $taxId, 'email' => $email]);
        $response->assertStatus(201);

        return self::text($this->decoded($response)['id']);
    }

    public function test_the_full_order_lifecycle(): void
    {
        $customerId = $this->createCustomer('123.456.789-00', 'ana@example.com');

        $created = $this->decoded($this->request('POST', '/orders', $this->user(), [
            'customer_id' => $customerId,
            'items' => [['description' => 'Pen', 'unit_price' => 2.50, 'quantity' => 4]],
        ]));
        $path = '/orders/' . self::text($created['id']);
        $added = $this->decoded($this->request('POST', "{$path}/items", $this->user(), ['description' => 'Ink', 'unit_price' => 0.10, 'quantity' => 3]));
        $confirmed = $this->decoded($this->request('POST', "{$path}/confirm", $this->user()));
        $editAfter = $this->decoded($this->request('POST', "{$path}/items", $this->user(), ['description' => 'Late', 'unit_price' => 1, 'quantity' => 1]));
        $canceled = $this->decoded($this->request('POST', "{$path}/cancel", $this->user()));
        $cancelAgain = $this->decoded($this->request('POST', "{$path}/cancel", $this->user()));
        $stored = $this->decoded($this->request('GET', $path, $this->user()));

        self::assertSame('OPEN', $created['status']);
        self::assertEquals(10, $created['total']);
        self::assertEquals(10.3, $added['total']);
        self::assertSame('CONFIRMED', $confirmed['status']);
        self::assertSame('VALIDATION-02', $editAfter['code']);
        self::assertSame('CANCELED', $canceled['status']);
        self::assertSame('VALIDATION-08', $cancelAgain['code']);
        self::assertSame('CANCELED', $stored['status']);
        self::assertCount(2, $this->publisher->events);
        self::assertSame('ana@example.com', $this->publisher->events[0]->customerEmail);
    }

    public function test_customer_rules_over_http(): void
    {
        $customerId = $this->createCustomer('12345678', 'ana@example.com');

        $duplicate = $this->request('POST', '/customers', $this->admin(), ['name' => 'B', 'tax_id' => '123-456.78', 'email' => 'b@example.com']);
        $asUser = $this->request('POST', '/customers', $this->user(), ['name' => 'C', 'tax_id' => '99999999', 'email' => 'c@example.com']);
        $order = $this->decoded($this->request('POST', '/orders', $this->user(), ['customer_id' => $customerId]));
        $blocked = $this->request('DELETE', "/customers/{$customerId}", $this->admin());
        $this->request('DELETE', '/orders/' . self::text($order['id']), $this->user())->assertNoContent();
        $deleted = $this->request('DELETE', "/customers/{$customerId}", $this->admin());
        $gone = $this->request('GET', "/customers/{$customerId}", $this->user());
        $orderForGone = $this->request('POST', '/orders', $this->user(), ['customer_id' => $customerId]);

        self::assertSame('CONFLICT-01', $this->decoded($duplicate)['code']);
        self::assertSame('AUTH-02', $this->decoded($asUser)['code']);
        self::assertSame('CONFLICT-03', $this->decoded($blocked)['code']);
        $deleted->assertNoContent();
        self::assertSame('RESOURCE-NOT-FOUND-01', $this->decoded($gone)['code']);
        self::assertSame('VALIDATION-07', $this->decoded($orderForGone)['code']);
    }

    public function test_search_over_http(): void
    {
        $this->createCustomer('11111111', 'a@example.com');
        $this->createCustomer('22222222', 'b@example.com');

        $paged = $this->decoded($this->request('GET', '/customers/search?page=1&size=1&sort=-created_at', $this->user()));
        $filtered = $this->decoded($this->request('POST', '/customers/search', $this->user(), [
            'size' => 10, 'filters' => ['tax_id' => [['op' => 'eq', 'value' => '22222222']]],
        ]));
        $badSort = $this->decoded($this->request('GET', '/customers/search?sort=password', $this->user()));
        $badType = $this->decoded($this->request('POST', '/orders/search', $this->user(), ['filters' => ['version' => [['op' => 'eq', 'value' => 'abc']]]]));
        $injection = $this->request('POST', '/customers/search', $this->user(), [
            'filters' => ['name' => [['op' => 'lk', 'value' => "'; DROP TABLE customers; --"]]],
        ]);

        self::assertSame(2, $paged['total']);
        self::assertSame(1, $filtered['total']);
        self::assertSame('VALIDATION-06', $badSort['code']);
        self::assertSame('VALIDATION-05', $badType['code']);
        $injection->assertOk();
        self::assertSame(2, $this->decoded($this->request('GET', '/customers/search', $this->user()))['total'], 'the table must still be there');
    }

    public function test_health_reports_the_database(): void
    {
        $this->request('GET', '/health')->assertOk();
    }
}
