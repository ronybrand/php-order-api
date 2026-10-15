<?php

declare(strict_types=1);

namespace App\Application;

/** Whether the service can do its work (the database answers). */
interface HealthCheck
{
    public function isHealthy(): bool;
}
