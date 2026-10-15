<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Order;

final class CustomerApiTest extends ApiTestCase
{
    public function test_creating_a_customer_returns_only_the_id_and_the_name(): void
    {
        $response = $this->request('POST', '/customers', $this->admin(), [
            'name' => 'Ana', 'tax_id' => '123.456-78', 'passport_number' => 'AB123456', 'email' => 'ana@example.com',
        ]);

        $response->assertStatus(201);
        $body = $this->decoded($response);
        self::assertSame(['id', 'name'], array_keys($body), 'PII must stay out of the response');
        self::assertSame('Ana', $body['name']);
        $stored = array_values($this->store->customers)[0];
        self::assertSame('12345678', $stored->taxId);
        self::assertSame('admin-1', $stored->createdBy);
    }

    public function test_a_duplicate_tax_id_is_a_conflict_that_does_not_echo_it(): void
    {
        $this->request('POST', '/customers', $this->admin(), ['name' => 'A', 'tax_id' => '12345678', 'email' => 'a@example.com'])->assertStatus(201);

        $response = $this->request('POST', '/customers', $this->admin(), ['name' => 'B', 'tax_id' => '123-456.78', 'email' => 'b@example.com']);

        $response->assertStatus(409);
        self::assertSame('CONFLICT-01', $this->decoded($response)['code']);
        self::assertStringNotContainsString('12345678', (string) $response->getContent());
    }

    public function test_validation_failures_name_the_field(): void
    {
        $response = $this->request('POST', '/customers', $this->admin(), ['name' => 'Ana', 'tax_id' => 'x', 'email' => 'nope']);

        $response->assertStatus(400);
        $body = $this->decoded($response);
        self::assertSame('VALIDATION-01', $body['code']);
        self::assertIsArray($body['params']);
        self::assertSame('tax_id', $body['params']['field']);
    }

    public function test_get_update_and_delete(): void
    {
        $customer = $this->store->seedCustomer(taxId: 'TAX0001');
        $path = '/customers/' . $customer->id;

        $this->request('GET', $path, $this->user())->assertOk()->assertJson(['id' => $customer->id, 'name' => 'Ada'] + ['name' => 'Ada']);
        $updated = $this->request('PUT', $path, $this->admin(), ['name' => 'New', 'tax_id' => 'TAX0001', 'email' => 'new@example.com']);
        $deleted = $this->request('DELETE', $path, $this->admin());
        $gone = $this->request('GET', $path, $this->user());

        $updated->assertOk();
        self::assertSame('New', $this->decoded($updated)['name']);
        $deleted->assertNoContent();
        $gone->assertNotFound();
        self::assertSame('RESOURCE-NOT-FOUND-01', $this->decoded($gone)['code']);
    }

    public function test_deleting_a_customer_with_active_orders_is_a_conflict(): void
    {
        $customer = $this->store->seedCustomer();
        $this->store->repos()->orders->create(Order::open((string) $customer->id, [], 'u', $this->clock->now()));

        $response = $this->request('DELETE', '/customers/' . $customer->id, $this->admin());

        $response->assertStatus(409);
        self::assertSame('CONFLICT-03', $this->decoded($response)['code']);
    }
}
