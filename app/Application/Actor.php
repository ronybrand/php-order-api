<?php

declare(strict_types=1);

namespace App\Application;

/** The authenticated caller. The audit columns (created_by...) always come from here. */
final readonly class Actor
{
    public const string SYSTEM = 'system';

    /** @param list<string> $roles */
    public function __construct(
        public string $id,
        public array $roles = [],
    ) {}

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
