<?php

declare(strict_types=1);

namespace App\Infrastructure\Postgres;

use App\Application\HealthCheck;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class PostgresHealth implements HealthCheck
{
    public function __construct(private ConnectionInterface $db, private LoggerInterface $logger) {}

    public function isHealthy(): bool
    {
        try {
            $this->db->selectOne('SELECT 1');

            return true;
        } catch (Throwable $e) {
            $this->logger->error('health check failed', ['exception' => $e::class]);

            return false;
        }
    }
}
