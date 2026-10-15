<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use Psr\Clock\ClockInterface;

/** Remembers recently completed message ids: bounded in size and in time, so it cannot leak memory. */
final class SeenSet
{
    /** @var array<string, int> message id => unix time it was completed */
    private array $entries = [];

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly int $ttlSeconds = 3600,
        private readonly int $capacity = 10_000,
    ) {}

    public function has(string $id): bool
    {
        $at = $this->entries[$id] ?? null;
        if ($at !== null && $this->now() - $at > $this->ttlSeconds) {
            unset($this->entries[$id]);

            return false;
        }

        return $at !== null;
    }

    public function add(string $id): void
    {
        if (count($this->entries) >= $this->capacity) {
            $this->evict();
        }
        $this->entries[$id] = $this->now();
    }

    public function size(): int
    {
        return count($this->entries);
    }

    /** Drops the expired entries and, if that was not enough, the oldest one. */
    private function evict(): void
    {
        $now = $this->now();
        foreach ($this->entries as $id => $at) {
            if ($now - $at > $this->ttlSeconds) {
                unset($this->entries[$id]);
            }
        }
        if (count($this->entries) >= $this->capacity) {
            asort($this->entries);
            unset($this->entries[array_key_first($this->entries)]);
        }
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
