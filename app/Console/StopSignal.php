<?php

declare(strict_types=1);

namespace App\Console;

/** Set by the SIGINT/SIGTERM handler, read by the worker loop: the worker finishes the current message and exits. */
final class StopSignal
{
    private bool $requested = false;

    public function request(): void
    {
        $this->requested = true;
    }

    public function requested(): bool
    {
        return $this->requested;
    }
}
