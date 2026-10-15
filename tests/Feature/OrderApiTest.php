<?php

declare(strict_types=1);

namespace Tests\Feature;

final class OrderApiTest extends ApiTestCase
{
    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerId = (string) $this->store->seedCustomer('ana@example.com')->id;
    }

    /** @return array<string, mixed> */
    private function createOrder(): array
    {
        $response = $this->request('POST', '/orders', $this->user(), [
            'customer_id' => $this->customerId,
            'items' => [['description' => 'Pen', 'unit_price' => 2.50, 'quantity' => 4]],
        ]);
        $response->assertStatus(201);

        return $this->decoded($response);
    }

    public function test_the_response_has_items_derived_amounts_and_numeric_money(): void
    {
        $order = $this->createOrder();

        self::assertSame('OPEN', $order['status']);
        self::assertSame(1, $order['version']);
        self::assertSame($this->customerId, $order['customer_id']);
        self::assertEquals(10, $order['total'], 'the total is a JSON number');
        self::assertIsArray($order['items']);
        self::assertCount(1, $order['items']);
        $item = $order['items'][0];
        self::assertIsArray($item);
        self::assertEquals(2.5, $item['unit_price']);
        self::assertEquals(10, $item['subtotal']);
        self::assertSame(4, $item['quantity']);
        self::assertSame($order['id'], $item['order_id']);
    }

    public function test_money_arrives_without_losing_precision(): void
    {
        $response = $this->request('POST', '/orders', $this->user(), [
            'customer_id' => $this->customerId,
            'items' => [['description' => 'Ink', 'unit_price' => 0.10, 'quantity' => 3], ['description' => 'Pen', 'unit_price' => '0.20', 'quantity' => 1]],
        ]);

        $response->assertStatus(201);
        self::assertEquals(0.5, $this->decoded($response)['total']);
    }

    public function test_an_order_for_an_unknown_customer_is_validation_07(): void
    {
        $response = $this->request('POST', '/orders', $this->user(), ['customer_id' => self::CUSTOMER_ID]);

        $response->assertStatus(400);
        self::assertSame('VALIDATION-07', $this->decoded($response)['code']);
    }

    public function test_the_lifecycle_and_its_rule_violations(): void
    {
        $order = $this->createOrder();
        $path = '/orders/' . self::text($order['id']);

        $added = $this->request('POST', "{$path}/items", $this->user(), ['description' => 'Ink', 'unit_price' => 0.10, 'quantity' => 3]);
        $confirmed = $this->request('POST', "{$path}/confirm", $this->user());
        $editAfter = $this->request('POST', "{$path}/items", $this->user(), ['description' => 'Late', 'unit_price' => 1, 'quantity' => 1]);
        $canceled = $this->request('POST', "{$path}/cancel", $this->user());
        $cancelAgain = $this->request('POST', "{$path}/cancel", $this->user());

        $added->assertOk();
        self::assertEquals(10.3, $this->decoded($added)['total']);
        self::assertSame('CONFIRMED', $this->decoded($confirmed)['status']);
        self::assertSame('VALIDATION-02', $this->decoded($editAfter)['code']);
        self::assertSame('CANCELED', $this->decoded($canceled)['status']);
        self::assertSame('VALIDATION-08', $this->decoded($cancelAgain)['code']);
        self::assertCount(2, $this->publisher->events, 'confirm and cancel each announce the change');
        self::assertSame('ana@example.com', $this->publisher->events[0]->customerEmail);
    }

    public function test_item_routes(): void
    {
        $order = $this->createOrder();
        self::assertIsArray($order['items']);
        $item = $order['items'][0];
        self::assertIsArray($item);
        $itemPath = '/orders/' . self::text($order['id']) . '/items/' . self::text($item['id']);

        $patched = $this->request('PATCH', $itemPath, $this->user(), ['quantity' => 7]);
        $invalid = $this->request('PATCH', $itemPath, $this->user(), ['quantity' => 0]);
        $removed = $this->request('DELETE', $itemPath, $this->user());
        $missing = $this->request('DELETE', $itemPath, $this->user());

        $patchedItems = $this->decoded($patched)['items'];
        self::assertIsArray($patchedItems);
        self::assertIsArray($patchedItems[0]);
        self::assertSame(7, $patchedItems[0]['quantity']);
        self::assertSame('VALIDATION-01', $this->decoded($invalid)['code']);
        $removed->assertOk();
        self::assertSame([], $this->decoded($removed)['items']);
        $missing->assertNotFound();
        self::assertSame('RESOURCE-NOT-FOUND-03', $this->decoded($missing)['code']);
    }

    public function test_confirm_of_an_empty_order_and_unknown_orders(): void
    {
        $empty = $this->request('POST', '/orders', $this->user(), ['customer_id' => $this->customerId]);
        $emptyId = self::text($this->decoded($empty)['id']);

        $confirm = $this->request('POST', "/orders/{$emptyId}/confirm", $this->user());
        $unknown = $this->request('GET', '/orders/' . self::ORDER_ID, $this->user());

        self::assertSame('VALIDATION-03', $this->decoded($confirm)['code']);
        $unknown->assertNotFound();
        self::assertSame('RESOURCE-NOT-FOUND-02', $this->decoded($unknown)['code']);
    }

    public function test_deleting_an_order(): void
    {
        $order = $this->createOrder();

        $this->request('DELETE', '/orders/' . self::text($order['id']), $this->user())->assertNoContent();
        $this->request('GET', '/orders/' . self::text($order['id']), $this->user())->assertNotFound();
    }

    public function test_item_validation_over_http(): void
    {
        $bad = [
            ['description' => '', 'unit_price' => 1, 'quantity' => 1],
            ['description' => 'x', 'unit_price' => 0, 'quantity' => 1],
            ['description' => 'x', 'unit_price' => 1.001, 'quantity' => 1],
            ['description' => 'x', 'unit_price' => 1, 'quantity' => 1.5],
            ['description' => 'x', 'unit_price' => 'abc', 'quantity' => 1],
            ['description' => 'x', 'unit_price' => 1],
        ];
        foreach ($bad as $item) {
            $response = $this->request('POST', '/orders', $this->user(), ['customer_id' => $this->customerId, 'items' => [$item]]);

            $response->assertStatus(400);
            self::assertSame('VALIDATION-01', $this->decoded($response)['code'], (string) json_encode($item));
        }
        self::assertSame([], $this->store->orders);
    }
}
