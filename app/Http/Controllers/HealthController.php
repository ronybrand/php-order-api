<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\HealthCheck;
use Illuminate\Http\JsonResponse;

final readonly class HealthController
{
    public function __construct(private HealthCheck $health) {}

    public function __invoke(): JsonResponse
    {
        // The reason a check failed goes to the logs, never to an unauthenticated caller.
        return $this->health->isHealthy()
            ? new JsonResponse(['status' => 'ok'])
            : new JsonResponse(['status' => 'unavailable'], 503);
    }
}
