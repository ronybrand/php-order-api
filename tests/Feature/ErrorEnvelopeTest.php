<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Store;
use RuntimeException;

final class ErrorEnvelopeTest extends ApiTestCase
{
    public function test_an_unknown_route_uses_the_error_envelope(): void
    {
        $response = $this->request('GET', '/nope', $this->user());

        $response->assertStatus(404);
        $body = $this->decoded($response);
        self::assertSame('RESOURCE-NOT-FOUND-00', $body['code']);
        self::assertSame([], $body['params']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', self::text($body['request_id']));
        self::assertSame($body['request_id'], $response->headers->get('X-Request-Id'));
    }

    public function test_a_wrong_method_is_405_with_the_allow_header(): void
    {
        $response = $this->request('PUT', '/orders', $this->user(), '{}');

        $response->assertStatus(405);
        self::assertSame('VALIDATION-13', $this->decoded($response)['code']);
        self::assertStringContainsString('POST', self::header($response, 'Allow'));
    }

    public function test_malformed_or_unexpected_bodies_are_validation_01_without_echoing_the_content(): void
    {
        $bodies = [
            '{"name":"Ana","tax_id":"12345678","email":"a@b.co","admin":true}',
            '{',
            '',
            '[]',
            '"text"',
            '{"name":1,"tax_id":"12345678","email":"a@b.co"}',
            '{"name":"Ana","tax_id":"12345678","email":"a@b.co"} {"x":1}',
        ];
        foreach ($bodies as $body) {
            $response = $this->request('POST', '/customers', $this->admin(), $body);

            $response->assertStatus(400);
            self::assertSame('VALIDATION-01', $this->decoded($response)['code'], $body);
            self::assertStringNotContainsString('admin', (string) $response->getContent());
        }
    }

    public function test_an_unexpected_failure_is_internal_00_and_never_leaks_its_message(): void
    {
        $this->app->instance(Store::class, new class implements Store {
            public function repos(): \App\Application\Repositories
            {
                throw new RuntimeException('connection string postgres://user:secret@db');
            }

            public function inTransaction(callable $work): mixed
            {
                throw new RuntimeException('secret');
            }
        });

        $response = $this->request('GET', '/orders/' . self::ORDER_ID, $this->user());

        $response->assertStatus(500);
        self::assertSame('INTERNAL-00', $this->decoded($response)['code']);
        self::assertStringNotContainsString('secret', (string) $response->getContent());
    }

    public function test_path_ids_must_be_uuids(): void
    {
        foreach (['/orders/not-a-uuid', '/customers/123'] as $path) {
            $response = $this->request('GET', $path, $this->user());

            $response->assertStatus(400);
            self::assertSame('VALIDATION-01', $this->decoded($response)['code']);
        }
        $this->request('DELETE', '/orders/' . self::ORDER_ID . '/items/xyz', $this->user())->assertStatus(400);
    }
}
