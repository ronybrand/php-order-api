<?php

declare(strict_types=1);

namespace App\Application;

/** The set of repositories bound to one connection or one transaction. */
final readonly class Repositories
{
    public function __construct(
        public CustomerRepository $customers,
        public OrderRepository $orders,
    ) {}
}
