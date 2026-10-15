<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Application\Condition;
use App\Application\SearchRequest;
use App\Domain\Error\ApiException;
use App\Domain\Error\ErrorCode;
use App\Infrastructure\Postgres\Entities;
use App\Infrastructure\Postgres\SearchEntity;
use App\Infrastructure\Postgres\SearchQueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchQueryBuilderTest extends TestCase
{
    private const string ID = '0b9d3bfa-1d54-4f32-9d0e-6a8a4b7b6a11';

    /** @param array<string, list<Condition>> $filters */
    private static function request(array $filters = [], ?string $sort = null): SearchRequest
    {
        return new SearchRequest(0, 10, $sort, $filters);
    }

    /** @return array<string, array{SearchEntity, SearchRequest, string, list<mixed>}> */
    public static function operators(): array
    {
        return [
            'no filter' => [Entities::customers(), self::request(), 'deleted_at IS NULL', []],
            'eq is the default operator' => [
                Entities::customers(),
                self::request(['name' => [new Condition('', 'Ana')]]),
                'deleted_at IS NULL AND name = ?',
                ['Ana'],
            ],
            'neq' => [
                Entities::orders(),
                self::request(['status' => [new Condition('neq', 'OPEN')]]),
                'deleted_at IS NULL AND status <> ?',
                ['OPEN'],
            ],
            'gte on an integer, from a JSON float' => [
                Entities::orders(),
                self::request(['version' => [new Condition('gte', 2.0)]]),
                'deleted_at IS NULL AND version >= ?',
                [2],
            ],
            'integer from a numeric string' => [
                Entities::orders(),
                self::request(['version' => [new Condition('eq', '7')]]),
                'deleted_at IS NULL AND version = ?',
                [7],
            ],
            'in with uuids' => [
                Entities::orders(),
                self::request(['customer_id' => [new Condition('in', [self::ID, strtoupper(self::ID)])]]),
                'deleted_at IS NULL AND customer_id IN (?, ?)',
                [self::ID, self::ID],
            ],
            'between' => [
                Entities::customers(),
                self::request(['version' => [new Condition('between', [1, 5])]]),
                'deleted_at IS NULL AND version BETWEEN ? AND ?',
                [1, 5],
            ],
            'timestamps are normalized to UTC' => [
                Entities::customers(),
                self::request(['created_at' => [new Condition('gt', '2026-01-02T03:04:05-03:00')]]),
                'deleted_at IS NULL AND created_at > ?',
                ['2026-01-02T06:04:05.000000Z'],
            ],
            'a plain date is accepted' => [
                Entities::customers(),
                self::request(['created_at' => [new Condition('gte', '2026-01-02')]]),
                'deleted_at IS NULL AND created_at >= ?',
                ['2026-01-02T00:00:00.000000Z'],
            ],
            'lk escapes the LIKE wildcards' => [
                Entities::customers(),
                self::request(['name' => [new Condition('lk', '50%_\\')]]),
                "deleted_at IS NULL AND name ILIKE ? ESCAPE '\\'",
                ['%50\\%\\_\\\\%'],
            ],
            'an unknown filter field is ignored' => [
                Entities::customers(),
                self::request(['nope' => [new Condition('eq', 'x')]]),
                'deleted_at IS NULL',
                [],
            ],
            'conditions come out in a deterministic order' => [
                Entities::customers(),
                self::request(['name' => [new Condition('eq', 'a')], 'email' => [new Condition('eq', 'b')]]),
                'deleted_at IS NULL AND email = ? AND name = ?',
                ['b', 'a'],
            ],
        ];
    }

    /** @param list<mixed> $bindings */
    #[DataProvider('operators')]
    public function test_builds_the_where_clause_and_its_bindings(SearchEntity $entity, SearchRequest $request, string $where, array $bindings): void
    {
        $query = (new SearchQueryBuilder())->build($entity, $request);

        self::assertSame($where, $query->where);
        self::assertSame($bindings, $query->bindings);
    }

    /** @return array<string, array{SearchEntity, SearchRequest}> */
    public static function invalidFilters(): array
    {
        $customers = Entities::customers();
        $orders = Entities::orders();

        return [
            'string where an integer is expected' => [$customers, self::request(['version' => [new Condition('eq', 'abc')]])],
            'fractional integer' => [$customers, self::request(['version' => [new Condition('eq', 1.5)]])],
            'boolean for an integer' => [$customers, self::request(['version' => [new Condition('eq', true)]])],
            'bad uuid' => [$customers, self::request(['id' => [new Condition('eq', 'not-a-uuid')]])],
            'bad timestamp' => [$customers, self::request(['created_at' => [new Condition('gt', 'yesterday')]])],
            'unknown enum value' => [$orders, self::request(['status' => [new Condition('eq', 'SHIPPED')]])],
            'ordering operator on an enum' => [$orders, self::request(['status' => [new Condition('gt', 'OPEN')]])],
            'between on an enum' => [$orders, self::request(['status' => [new Condition('between', ['OPEN', 'CANCELED'])]])],
            'between with three values' => [$customers, self::request(['version' => [new Condition('between', [1, 2, 3])]])],
            'in with a scalar' => [$customers, self::request(['name' => [new Condition('in', 'Ana')]])],
            'in with no values' => [$customers, self::request(['name' => [new Condition('in', [])]])],
            'lk on a non text field' => [$customers, self::request(['version' => [new Condition('lk', '1')]])],
            'lk with a non string' => [$customers, self::request(['name' => [new Condition('lk', 5)]])],
            'unknown operator' => [$customers, self::request(['name' => [new Condition('regex', '.*')]])],
            'sql in the value is only data' => [$customers, self::request(['version' => [new Condition('eq', '1; DROP TABLE customers')]])],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_rejects_invalid_filters_with_validation_05(SearchEntity $entity, SearchRequest $request): void
    {
        try {
            (new SearchQueryBuilder())->build($entity, $request);
            self::fail('expected a validation error');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::InvalidFilter, $e->errorCode);
        }
    }

    public function test_sorting(): void
    {
        $builder = new SearchQueryBuilder();

        self::assertSame('name ASC, id', $builder->build(Entities::customers(), self::request(sort: 'name'))->orderBy);
        self::assertSame('created_at DESC, id', $builder->build(Entities::customers(), self::request(sort: '-created_at'))->orderBy);
        self::assertSame('created_at DESC, id', $builder->build(Entities::customers(), self::request())->orderBy);
    }

    public function test_an_unknown_sort_field_is_validation_06_and_never_reaches_sql(): void
    {
        foreach (['password', 'name; DROP TABLE customers', 'deleted_at', '-deleted_by'] as $sort) {
            try {
                (new SearchQueryBuilder())->build(Entities::customers(), self::request(sort: $sort));
                self::fail("sort {$sort} should be refused");
            } catch (ApiException $e) {
                self::assertSame(ErrorCode::UnknownSort, $e->errorCode);
            }
        }
    }
}
