<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Error\ApiException;
use DateTimeImmutable;
use JsonSerializable;

/**
 * A registered customer. taxId, passportNumber and email are PII: they are never logged and never returned by
 * the API, which is why debug output (print_r, var_export, json_encode) exposes nothing but the id.
 */
final class Customer implements JsonSerializable
{
    public const int MAX_NAME_LENGTH = 200;
    public const int MAX_EMAIL_LENGTH = 320;
    private const int MIN_TAX_ID_SIGNIFICANT_LENGTH = 5;

    // PCRE "D" modifier: "$" matches only at the very end (no trailing-newline surprise).
    private const string TAX_ID_PATTERN = '/^[A-Za-z0-9.\/-]{5,20}$/D';
    private const string PASSPORT_PATTERN = '/^[A-Z0-9]{6,9}$/D';

    private function __construct(
        public private(set) ?string $id,
        public private(set) string $name,
        public private(set) string $taxId,
        public private(set) ?string $passportNumber,
        public private(set) string $email,
        public private(set) int $version,
        public private(set) DateTimeImmutable $createdAt,
        public private(set) DateTimeImmutable $updatedAt,
        public private(set) string $createdBy,
        public private(set) string $updatedBy,
    ) {}

    public static function create(CustomerInput $input, string $actor, DateTimeImmutable $now): self
    {
        $valid = self::validated($input);

        return new self(null, $valid->name, $valid->taxId, $valid->passportNumber, $valid->email, 1, $now, $now, $actor, $actor);
    }

    /** Rebuilds a stored customer: the data already passed validation when it was written. */
    public static function reconstitute(
        string $id,
        string $name,
        string $taxId,
        ?string $passportNumber,
        string $email,
        int $version,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        string $createdBy,
        string $updatedBy,
    ): self {
        return new self($id, $name, $taxId, $passportNumber, $email, $version, $createdAt, $updatedAt, $createdBy, $updatedBy);
    }

    /** PUT semantics: validates the input and overwrites the data. The customer is untouched when it is invalid. */
    public function replace(CustomerInput $input, string $actor, DateTimeImmutable $now): void
    {
        $valid = self::validated($input);
        $this->name = $valid->name;
        $this->taxId = $valid->taxId;
        $this->passportNumber = $valid->passportNumber;
        $this->email = $valid->email;
        $this->updatedAt = $now;
        $this->updatedBy = $actor;
    }

    public function persisted(string $id): void
    {
        $this->id = $id;
    }

    public function versionIncremented(): void
    {
        ++$this->version;
    }

    /** Tax id and passport are compared and stored in this form: "123.456-78" and "12345678" are one document. */
    public static function canonicalTaxId(string $raw): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $raw));
    }

    /** @return array{id: string|null} */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id];
    }

    /** @return array{id: string|null} */
    public function __debugInfo(): array
    {
        return ['id' => $this->id];
    }

    private static function validated(CustomerInput $input): CustomerInput
    {
        if (trim($input->name) === '') {
            throw ApiException::invalidField('name', 'name must not be blank');
        }
        if (mb_strlen($input->name) > self::MAX_NAME_LENGTH) {
            throw ApiException::invalidField('name', 'name is too long');
        }

        if (preg_match(self::TAX_ID_PATTERN, $input->taxId) !== 1) {
            throw ApiException::invalidField('tax_id', 'tax_id has an invalid format');
        }
        $taxId = self::canonicalTaxId($input->taxId);
        if (strlen($taxId) < self::MIN_TAX_ID_SIGNIFICANT_LENGTH) {
            throw ApiException::invalidField('tax_id', 'tax_id must contain at least 5 letters or digits');
        }

        $passport = null;
        if ($input->passportNumber !== null && $input->passportNumber !== '') {
            if (preg_match(self::PASSPORT_PATTERN, $input->passportNumber) !== 1) {
                throw ApiException::invalidField('passport_number', 'passport_number has an invalid format');
            }
            $passport = $input->passportNumber;
        }

        return new CustomerInput($input->name, $taxId, $passport, self::validEmail($input->email));
    }

    private static function validEmail(string $email): string
    {
        // FILTER_VALIDATE_EMAIL accepts only a bare address (no display name, no list, no comments).
        if ($email === '' || strlen($email) > self::MAX_EMAIL_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ApiException::invalidField('email', 'email is invalid');
        }

        return $email;
    }
}
