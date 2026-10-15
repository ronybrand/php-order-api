<?php

declare(strict_types=1);

namespace App\Infrastructure\Postgres;

use App\Domain\OrderStatus;

/** The searchable fields of each table. A test fails if a column of the schema is missing from here. */
final class Entities
{
    public static function customers(): SearchEntity
    {
        return new SearchEntity('customers', [
            'id' => new SearchField('id', FieldType::Uuid),
            'name' => new SearchField('name', FieldType::String),
            'tax_id' => new SearchField('tax_id', FieldType::String),
            'passport_number' => new SearchField('passport_number', FieldType::String),
            'email' => new SearchField('email', FieldType::String),
            'version' => new SearchField('version', FieldType::Integer),
            'created_at' => new SearchField('created_at', FieldType::Time),
            'updated_at' => new SearchField('updated_at', FieldType::Time),
            'created_by' => new SearchField('created_by', FieldType::String),
            'updated_by' => new SearchField('updated_by', FieldType::String),
        ]);
    }

    public static function orders(): SearchEntity
    {
        return new SearchEntity('orders', [
            'id' => new SearchField('id', FieldType::Uuid),
            'customer_id' => new SearchField('customer_id', FieldType::Uuid),
            'status' => new SearchField('status', FieldType::Enum, array_map(static fn(OrderStatus $s): string => $s->value, OrderStatus::cases())),
            'version' => new SearchField('version', FieldType::Integer),
            'created_at' => new SearchField('created_at', FieldType::Time),
            'updated_at' => new SearchField('updated_at', FieldType::Time),
            'created_by' => new SearchField('created_by', FieldType::String),
            'updated_by' => new SearchField('updated_by', FieldType::String),
        ]);
    }
}
