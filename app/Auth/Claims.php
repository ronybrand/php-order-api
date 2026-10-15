<?php

declare(strict_types=1);

namespace App\Auth;

/** What the API needs from a verified token. */
final readonly class Claims
{
    public const string ROLE_USER = 'user';
    public const string ROLE_ADMIN = 'admin';

    /** @param list<string> $roles */
    public function __construct(
        public string $subject,
        public array $roles,
    ) {}

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
