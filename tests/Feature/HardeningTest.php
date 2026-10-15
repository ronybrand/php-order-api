<?php

declare(strict_types=1);

namespace Tests\Feature;

use Ramsey\Uuid\Uuid;

final class HardeningTest extends ApiTestCase
{
    public function test_a_valid_request_id_is_kept_and_anything_else_is_replaced(): void
    {
        $valid = Uuid::uuid4()->toString();

        self::assertSame($valid, $this->request('GET', '/health', null, '', ['X-Request-Id' => $valid])->headers->get('X-Request-Id'));

        foreach (["abc\r\nX-Evil: 1", 'hello', str_replace('-', '', $valid), str_repeat('a', 5000)] as $bad) {
            $id = self::header($this->request('GET', '/health', null, '', ['X-Request-Id' => $bad]), 'X-Request-Id');
            self::assertTrue(Uuid::isValid($id));
            self::assertNotSame($bad, $id);
        }
        self::assertTrue(Uuid::isValid(self::header($this->request('GET', '/health'), 'X-Request-Id')));
    }

    public function test_responses_carry_the_security_headers_even_for_errors(): void
    {
        foreach ([$this->request('GET', '/health'), $this->request('GET', '/orders/search')] as $response) {
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
            self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
            self::assertStringContainsString('no-store', self::header($response, 'Cache-Control'));
            self::assertSame("default-src 'none'; frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        }
    }

    public function test_a_body_above_the_limit_is_413_whether_or_not_content_length_is_declared(): void
    {
        $big = '{"name":"' . str_repeat('x', 5000) . '"}';

        $declared = $this->request('POST', '/customers', $this->admin(), $big, ['Content-Length' => (string) strlen($big)]);
        $undeclared = $this->request('POST', '/customers', $this->admin(), $big);

        foreach ([$declared, $undeclared] as $response) {
            $response->assertStatus(413);
            self::assertSame('VALIDATION-12', $this->decoded($response)['code']);
        }
    }

    public function test_a_body_within_the_limit_is_processed(): void
    {
        $response = $this->request('POST', '/customers', $this->admin(), ['name' => 'Ana', 'tax_id' => '12345678', 'email' => 'ana@example.com']);

        $response->assertStatus(201);
    }

    public function test_cors_allows_only_the_configured_origins(): void
    {
        $allowed = $this->request('GET', '/health', null, '', ['Origin' => 'https://app.example']);
        $denied = $this->request('GET', '/health', null, '', ['Origin' => 'https://evil.example']);
        $preflight = $this->request('OPTIONS', '/orders', null, '', [
            'Origin' => 'https://app.example', 'Access-Control-Request-Method' => 'POST',
        ]);

        self::assertSame('https://app.example', $allowed->headers->get('Access-Control-Allow-Origin'));
        self::assertNull($denied->headers->get('Access-Control-Allow-Origin'));
        self::assertTrue($preflight->isSuccessful());
        self::assertNotNull($preflight->headers->get('Access-Control-Allow-Methods'));
    }

    public function test_the_write_budget_is_separate_from_the_general_budget_and_per_user(): void
    {
        $this->configure(['RATE_LIMIT_WRITES' => '2', 'RATE_LIMIT_PER_USER' => '100']);
        $customer = $this->store->seedCustomer();
        $order = $this->createOrder((string) $customer->id);

        $codes = [];
        for ($i = 0; $i < 3; ++$i) {
            $codes[] = $this->request('POST', "/orders/{$order}/cancel", $this->user())->getStatusCode();
        }
        $read = $this->request('GET', "/orders/{$order}", $this->user());
        $otherUser = $this->request('POST', "/orders/{$order}/cancel", $this->token('someone-else'));

        // the order creation above already used one of the two writes of user-1
        self::assertSame([200, 429, 429], $codes);
        self::assertSame('RATE-00', $this->decoded($this->request('POST', "/orders/{$order}/cancel", $this->user()))['code']);
        $read->assertOk();
        self::assertNotSame(429, $otherUser->getStatusCode(), 'another user must not share the exhausted budget');
    }

    public function test_a_429_tells_when_to_retry(): void
    {
        $this->configure(['RATE_LIMIT_PER_USER' => '1']);
        $this->request('GET', '/orders/search', $this->user())->assertOk();

        $limited = $this->request('GET', '/orders/search', $this->user());

        $limited->assertStatus(429);
        self::assertGreaterThanOrEqual(1, (int) $limited->headers->get('Retry-After'));
    }

    public function test_the_ip_limit_applies_before_authentication(): void
    {
        $this->configure(['RATE_LIMIT_PER_IP' => '2']);

        $codes = [];
        for ($i = 0; $i < 3; ++$i) {
            $codes[] = $this->request('GET', '/orders/' . self::ORDER_ID)->getStatusCode();
        }

        self::assertSame([401, 401, 429], $codes);
    }

    public function test_health_is_exempt_from_the_limits(): void
    {
        $this->configure(['RATE_LIMIT_PER_IP' => '1']);

        for ($i = 0; $i < 5; ++$i) {
            $this->request('GET', '/health')->assertOk();
        }
    }

    private function createOrder(string $customerId): string
    {
        $response = $this->request('POST', '/orders', $this->user(), ['customer_id' => $customerId]);
        $response->assertStatus(201);

        return self::text($this->decoded($response)['id']);
    }
}
