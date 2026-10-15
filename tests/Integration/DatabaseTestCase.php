<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Base of the integration tests: they need a real PostgreSQL (DB_URL), e.g.
 * DB_URL=postgres://orders:orders@127.0.0.1:5432/orders composer test:integration. Without it they are skipped,
 * so the default test run stays hermetic.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected ConnectionInterface $db;

    protected function setUp(): void
    {
        parent::setUp();
        $url = getenv('DB_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('DB_URL not set');
        }
        self::assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->db = DB::connection();
        $this->db->unprepared('TRUNCATE order_items, orders, customers CASCADE');
    }
}
