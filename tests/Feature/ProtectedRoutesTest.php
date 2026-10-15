<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;

final class ProtectedRoutesTest extends ApiTestCase
{
    /** @return array<string, array{string, string}> */
    public static function protectedRoutes(): array
    {
        $order = '/orders/' . self::ORDER_ID;
        $item = $order . '/items/' . self::ITEM_ID;
        $customer = '/customers/' . self::CUSTOMER_ID;
        $routes = [
            'POST /orders', 'GET /orders/search', 'POST /orders/search', "GET {$order}", "DELETE {$order}",
            "POST {$order}/items", "PATCH {$item}", "DELETE {$item}", "POST {$order}/confirm", "POST {$order}/cancel",
            'POST /customers', 'GET /customers/search', 'POST /customers/search', "GET {$customer}", "PUT {$customer}", "DELETE {$customer}",
        ];

        return array_combine($routes, array_map(static fn(string $r): array => explode(' ', $r, 2), $routes));
    }

    #[DataProvider('protectedRoutes')]
    public function test_every_protected_route_requires_authentication(string $method, string $path): void
    {
        $response = $this->request($method, $path, null, '{}');

        $response->assertStatus(401);
        self::assertSame('AUTH-01', $this->decoded($response)['code']);
        self::assertNotSame('', $response->headers->get('WWW-Authenticate'));
    }

    public function test_a_forged_or_malformed_token_is_unauthenticated(): void
    {
        foreach (['not.a.token', 'x', ''] as $token) {
            $this->request('GET', '/orders/search', $token)->assertStatus(401);
        }
        $this->request('GET', '/orders/search', null, '', ['Authorization' => 'Basic abc'])->assertStatus(401);
    }

    public function test_customer_mutations_require_the_admin_role(): void
    {
        $body = ['name' => 'Ana', 'tax_id' => '12345678', 'email' => 'ana@example.com'];

        foreach ([['POST', '/customers'], ['PUT', '/customers/' . self::CUSTOMER_ID], ['DELETE', '/customers/' . self::CUSTOMER_ID]] as [$method, $path]) {
            $response = $this->request($method, $path, $this->user(), $body);
            $response->assertStatus(403);
            self::assertSame('AUTH-02', $this->decoded($response)['code']);
        }
    }

    public function test_reads_stay_open_to_any_authenticated_user(): void
    {
        $customer = $this->store->seedCustomer();

        $this->request('GET', '/customers/' . $customer->id, $this->user())->assertOk();
    }

    public function test_health_is_public(): void
    {
        $this->request('GET', '/health')->assertOk();
    }
}
