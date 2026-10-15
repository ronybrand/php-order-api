<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Customer;
use App\Domain\CustomerInput;
use App\Domain\Error\ApiException;
use App\Domain\Error\ErrorCode;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CustomerTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-14T12:00:00Z');
    }

    private static function input(
        string $name = 'Ada Lovelace',
        string $taxId = 'AB123456',
        ?string $passport = null,
        string $email = 'ada@example.com',
    ): CustomerInput {
        return new CustomerInput($name, $taxId, $passport, $email);
    }

    public function test_accepts_a_valid_input_and_fills_the_audit_fields(): void
    {
        $customer = Customer::create(self::input(), 'admin-1', $this->now);

        self::assertSame('admin-1', $customer->createdBy);
        self::assertSame('admin-1', $customer->updatedBy);
        self::assertEquals($this->now, $customer->createdAt);
        self::assertSame(1, $customer->version);
        self::assertNull($customer->id);
    }

    /** @return array<string, array{string, string}> */
    public static function canonicalTaxIds(): array
    {
        return [
            'punctuation' => ['123.456-78', '12345678'],
            'mixed case and separators' => ['ab-12.cd/9', 'AB12CD9'],
            'lower case' => ['abcde', 'ABCDE'],
            'already canonical' => ['AB123456', 'AB123456'],
        ];
    }

    #[DataProvider('canonicalTaxIds')]
    public function test_stores_the_tax_id_in_canonical_form(string $raw, string $expected): void
    {
        $customer = Customer::create(self::input(taxId: $raw), 'a', $this->now);

        self::assertSame($expected, $customer->taxId);
    }

    /** @return array<string, array{CustomerInput, string}> */
    public static function invalidInputs(): array
    {
        return [
            'blank name' => [self::input(name: "  \t"), 'name'],
            'name above the column' => [self::input(name: str_repeat('a', 201)), 'name'],
            'tax id too short' => [self::input(taxId: '1234'), 'tax_id'],
            'tax id too long' => [self::input(taxId: str_repeat('1', 21)), 'tax_id'],
            'tax id with a trailing newline' => [self::input(taxId: "12345678\n"), 'tax_id'],
            'tax id with only punctuation' => [self::input(taxId: '.....'), 'tax_id'],
            'tax id with fewer than 5 alphanumerics' => [self::input(taxId: 'A.B-C'), 'tax_id'],
            'lower-case passport' => [self::input(passport: 'ab123456'), 'passport_number'],
            'short passport' => [self::input(passport: 'AB123'), 'passport_number'],
            'empty email' => [self::input(email: ''), 'email'],
            'email without domain' => [self::input(email: 'ada'), 'email'],
            'email with a display name' => [self::input(email: 'Ada <ada@example.com>'), 'email'],
            'email list' => [self::input(email: 'a@example.com, b@example.com'), 'email'],
            'email above the column' => [self::input(email: str_repeat('a', 320) . '@example.com'), 'email'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_rejects_invalid_input(CustomerInput $input, string $field): void
    {
        try {
            Customer::create($input, 'a', $this->now);
            self::fail('expected a validation error');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::InvalidRequest, $e->errorCode);
            self::assertSame($field, $e->params['field'] ?? null);
        }
    }

    public function test_the_passport_is_optional(): void
    {
        $none = Customer::create(self::input(passport: null), 'a', $this->now);
        $empty = Customer::create(self::input(passport: ''), 'a', $this->now);
        $given = Customer::create(self::input(passport: 'AB123456'), 'a', $this->now);

        self::assertNull($none->passportNumber);
        self::assertNull($empty->passportNumber);
        self::assertSame('AB123456', $given->passportNumber);
    }

    public function test_replace_keeps_the_original_creation_audit(): void
    {
        $customer = Customer::create(self::input(), 'creator', $this->now);
        $later = $this->now->modify('+1 hour');

        $customer->replace(self::input(name: 'New Name'), 'editor', $later);

        self::assertSame('New Name', $customer->name);
        self::assertSame('creator', $customer->createdBy);
        self::assertSame('editor', $customer->updatedBy);
        self::assertEquals($later, $customer->updatedAt);
    }

    public function test_replace_does_not_touch_the_customer_when_the_input_is_invalid(): void
    {
        $customer = Customer::create(self::input(), 'creator', $this->now);

        try {
            $customer->replace(self::input(name: ''), 'editor', $this->now->modify('+1 hour'));
            self::fail('expected a validation error');
        } catch (ApiException) {
        }

        self::assertSame('Ada Lovelace', $customer->name);
        self::assertSame('creator', $customer->updatedBy);
    }

    public function test_debug_output_never_contains_pii(): void
    {
        $customer = Customer::create(self::input(passport: 'AB654321'), 'a', $this->now);

        $dumped = print_r($customer, true) . json_encode($customer, JSON_THROW_ON_ERROR);

        foreach (['AB123456', 'AB654321', 'ada@example.com', 'Ada Lovelace'] as $pii) {
            self::assertStringNotContainsString($pii, $dumped);
        }
    }
}
