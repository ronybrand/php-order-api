<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Postgres\Entities;

/**
 * Guard against drift between the schema and the search whitelist: a new column that nobody added to the entity
 * would silently be impossible to filter or sort on.
 */
final class SearchColumnsTest extends DatabaseTestCase
{
    /** The soft-delete markers are deliberately not searchable: deleted rows are never visible. */
    private const array NOT_SEARCHABLE = ['deleted_at', 'deleted_by'];

    public function test_the_searchable_fields_cover_every_column_of_each_table(): void
    {
        foreach ([Entities::customers(), Entities::orders()] as $entity) {
            $rows = $this->db->select(
                'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ?',
                [$entity->table],
            );
            $columns = array_map(static function (mixed $row): string {
                \assert($row instanceof \stdClass && is_string($row->column_name));

                return $row->column_name;
            }, $rows);
            $searchable = array_map(static fn($field): string => $field->column, array_values($entity->fields));

            foreach ($columns as $column) {
                if (!in_array($column, self::NOT_SEARCHABLE, true)) {
                    self::assertContains($column, $searchable, "{$entity->table}.{$column} exists but is not searchable: add it to Entities (or to NOT_SEARCHABLE, with a reason)");
                }
            }
            foreach ($searchable as $column) {
                self::assertContains($column, $columns, "search entity {$entity->table} lists {$column}, which is not a column of the table");
            }
        }
    }
}
