<?php

declare(strict_types=1);

namespace App\Domain;

/** The data a client supplies to create or replace a customer. Unvalidated. */
final readonly class CustomerInput
{
    public function __construct(
        public string $name,
        public string $taxId,
        public ?string $passportNumber,
        public string $email,
    ) {}
}
