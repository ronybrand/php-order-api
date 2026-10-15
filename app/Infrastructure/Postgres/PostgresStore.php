<?php

declare(strict_types=1);

namespace App\Infrastructure\Postgres;

use App\Application\Repositories;
use App\Application\Store;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * The Store backed by a PostgreSQL connection. Inside a transaction the same connection is used by every
 * repository, so the locks and writes of a use case share one transaction.
 */
final readonly class PostgresStore implements Store
{
    public function __construct(private ConnectionInterface $db) {}

    public function repos(): Repositories
    {
        return new Repositories(new PostgresCustomers($this->db), new PostgresOrders($this->db));
    }

    public function inTransaction(callable $work): mixed
    {
        try {
            // A single attempt: retrying a use case on deadlock is a decision for the caller, not the driver.
            return $this->db->transaction(fn() => $work($this->repos()), 1);
        } catch (Throwable $e) {
            throw PostgresErrors::translate($e);
        }
    }
}
