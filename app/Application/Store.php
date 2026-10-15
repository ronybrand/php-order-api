<?php

declare(strict_types=1);

namespace App\Application;

/** Gives the use cases their repositories, either directly or inside a transaction. */
interface Store
{
    /** Repositories running each statement on its own. */
    public function repos(): Repositories;

    /**
     * Runs $work in a transaction: commits when it returns, rolls back when it throws (and rethrows).
     *
     * @template T
     *
     * @param callable(Repositories): T $work
     * @return T
     */
    public function inTransaction(callable $work): mixed;
}
