<?php

declare(strict_types=1);

namespace Tests\Feature;

final class SearchApiTest extends ApiTestCase
{
    public function test_get_search_reads_page_size_and_sort_only(): void
    {
        $this->store->seedCustomer(taxId: 'AAAAA1');
        $this->store->seedCustomer(taxId: 'BBBBB2');

        $response = $this->request('GET', '/customers/search?page=1&size=1&sort=-created_at&name=ignored', $this->user());

        $response->assertOk();
        $body = $this->decoded($response);
        self::assertSame(2, $body['total']);
        self::assertSame(1, $body['page']);
        self::assertSame(1, $body['size']);
        $items = $body['items'];
        self::assertIsArray($items);
        self::assertIsArray($items[0]);
        self::assertSame(['id', 'name'], array_keys($items[0]));
    }

    public function test_get_search_rejects_non_numeric_paging(): void
    {
        foreach (['page=abc', 'size=1.5', 'size=99999999999999999999', 'page=-1', 'size=-1', 'size=101'] as $query) {
            $response = $this->request('GET', "/customers/search?{$query}", $this->user());

            $response->assertStatus(400);
            self::assertSame('VALIDATION-01', $this->decoded($response)['code'], $query);
        }
    }

    public function test_the_default_page_size_applies(): void
    {
        $body = $this->decoded($this->request('GET', '/orders/search', $this->user()));

        self::assertSame(20, $body['size']);
        self::assertSame(0, $body['page']);
    }

    public function test_post_search_forwards_the_filters(): void
    {
        $this->store->seedCustomer(taxId: 'AAAAA1');

        $response = $this->request('POST', '/customers/search', $this->user(), [
            'page' => 0, 'size' => 10, 'sort' => 'name',
            'filters' => ['tax_id' => [['op' => 'eq', 'value' => 'AAAAA1']]],
        ]);

        $response->assertOk();
    }

    public function test_post_search_rejects_a_malformed_filter_shape(): void
    {
        $bodies = [
            ['filters' => 'name'],
            ['filters' => ['name' => 'Ana']],
            ['filters' => ['name' => [['op' => 'eq', 'value' => 'x', 'extra' => 1]]]],
            ['filters' => ['name' => [['op' => 5, 'value' => 'x']]]],
            ['page' => 'one'],
            ['unknown' => true],
        ];
        foreach ($bodies as $body) {
            $response = $this->request('POST', '/customers/search', $this->user(), $body);

            $response->assertStatus(400);
            self::assertSame('VALIDATION-01', $this->decoded($response)['code'], (string) json_encode($body));
        }
    }

    public function test_structural_limits_apply_over_http(): void
    {
        $response = $this->request('POST', '/orders/search', $this->user(), [
            'filters' => ['version' => [['op' => 'in', 'value' => array_fill(0, 101, 1)]]],
        ]);

        $response->assertStatus(400);
        self::assertSame('VALIDATION-05', $this->decoded($response)['code']);
    }
}
