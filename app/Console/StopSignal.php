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

    /** Waits up to $seconds, waking early (in one-second steps) once a stop was requested. */
    public function sleep(int $seconds): void
    {
        for ($waited = 0; $waited < $seconds && !$this->requested; ++$waited) {
            sleep(1);
        }
    }

    public function requested(): bool
    {
        return $this->requested;
    }
}
