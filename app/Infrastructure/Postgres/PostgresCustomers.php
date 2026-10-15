<?php

declare(strict_types=1);

namespace App\Infrastructure\Postgres;

use App\Application\CustomerRepository;
use App\Application\Page;
use App\Application\SearchRequest;
use App\Domain\Customer;
use App\Domain\Error\ApiException;
use App\Domain\Error\ErrorCode;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use stdClass;

final readonly class PostgresCustomers implements CustomerRepository
{
    private const string COLUMNS = 'id, name, tax_id, passport_number, email, version, created_at, updated_at, created_by, updated_by';

    public function __construct(
        private ConnectionInterface $db,
        private SearchQueryBuilder $searches = new SearchQueryBuilder(),
    ) {}

    public function get(string $id): Customer
    {
        return $this->read($id, '');
    }

    public function lockForUpdate(string $id): Customer
    {
        return $this->read($id, ' FOR UPDATE');
    }

    public function lockForShare(string $id): Customer
    {
        return $this->read($id, ' FOR SHARE');
    }

    public function create(Customer $customer): void
    {
        $row = PostgresErrors::guard(fn() => $this->db->selectOne(
            'INSERT INTO customers (name, tax_id, passport_number, email, version, created_at, updated_at, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [
                $customer->name, $customer->taxId, $customer->passportNumber, $customer->email, $customer->version,
                $customer->createdAt->format('c'), $customer->updatedAt->format('c'), $customer->createdBy, $customer->updatedBy,
            ],
        ));
        \assert($row instanceof stdClass);
        $customer->persisted(Rows::string($row, 'id'));
    }

    public function update(Customer $customer): void
    {
        $affected = PostgresErrors::guard(fn() => $this->db->update(
            'UPDATE customers
                SET name = ?, tax_id = ?, passport_number = ?, email = ?, updated_at = ?, updated_by = ?, version = version + 1
              WHERE id = ? AND version = ? AND deleted_at IS NULL',
            [
                $customer->name, $customer->taxId, $customer->passportNumber, $customer->email,
                $customer->updatedAt->format('c'), $customer->updatedBy, $customer->id, $customer->version,
            ],
        ));
        if ($affected === 0) {
            throw PostgresErrors::concurrentModification();
        }
        $customer->versionIncremented();
    }

    public function softDelete(string $id, string $actor, DateTimeImmutable $at): void
    {
        $affected = Rows::isUuid($id) ? PostgresErrors::guard(fn() => $this->db->update(
            'UPDATE customers
                SET deleted_at = ?, deleted_by = ?, updated_at = ?, updated_by = ?, version = version + 1
              WHERE id = ? AND deleted_at IS NULL',
            [$at->format('c'), $actor, $at->format('c'), $actor, $id],
        )) : 0;
        if ($affected === 0) {
            throw self::notFound($id);
        }
    }

    public function taxIdTaken(string $taxId, ?string $except): bool
    {
        return $this->taken('tax_id', $taxId, $except);
    }

    public function passportTaken(string $passport, ?string $except): bool
    {
        return $this->taken('passport_number', $passport, $except);
    }

    public function search(SearchRequest $request): Page
    {
        $query = $this->searches->build(Entities::customers(), $request);
        $total = $this->db->selectOne("SELECT count(*) AS total FROM customers WHERE {$query->where}", $query->bindings);
        \assert($total instanceof stdClass);

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . " FROM customers WHERE {$query->where} ORDER BY {$query->orderBy} LIMIT ? OFFSET ?",
            [...$query->bindings, $request->size, $request->offset()],
        );

        return new Page(
            array_values(array_map(fn(mixed $row): Customer => self::hydrate(self::row($row)), $rows)),
            Rows::int($total, 'total'),
            $request->page,
            $request->size,
        );
    }

    private function read(string $id, string $lock): Customer
    {
        $row = Rows::isUuid($id)
            ? $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM customers WHERE id = ? AND deleted_at IS NULL' . $lock, [$id])
            : null;
        if (!$row instanceof stdClass) {
            throw self::notFound($id);
        }

        return self::hydrate($row);
    }

    /** The column name comes from this file only, never from client input. */
    private function taken(string $column, string $value, ?string $except): bool
    {
        $row = $this->db->selectOne(
            "SELECT EXISTS (SELECT 1 FROM customers WHERE {$column} = ? AND id IS DISTINCT FROM ?::uuid AND deleted_at IS NULL) AS taken",
            [$value, $except],
        );
        \assert($row instanceof stdClass);

        return (bool) $row->taken;
    }

    private static function hydrate(stdClass $row): Customer
    {
        return Customer::reconstitute(
            Rows::string($row, 'id'),
            Rows::string($row, 'name'),
            Rows::string($row, 'tax_id'),
            Rows::nullableString($row, 'passport_number'),
            Rows::string($row, 'email'),
            Rows::int($row, 'version'),
            Rows::time($row, 'created_at'),
            Rows::time($row, 'updated_at'),
            Rows::string($row, 'created_by'),
            Rows::string($row, 'updated_by'),
        );
    }

    private static function row(mixed $row): stdClass
    {
        \assert($row instanceof stdClass);

        return $row;
    }

    private static function notFound(string $id): ApiException
    {
        return ApiException::notFound(ErrorCode::CustomerNotFound, 'customer not found', ['id' => $id]);
    }
}
