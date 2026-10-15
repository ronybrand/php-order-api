<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Error\ApiException;
use App\Domain\Error\ErrorCode;

/**
 * The filter, sort and page of a search. Page is 0-based; sort is a field name with a "-" prefix for descending
 * order. Field names and value types are checked later, against the columns of the entity being searched.
 */
final readonly class SearchRequest
{
    public const int DEFAULT_PAGE_SIZE = 20;
    public const int MAX_PAGE_SIZE = 100;
    public const int MAX_FILTER_FIELDS = 10;
    public const int MAX_CONDITIONS_PER_FIELD = 10;
    public const int MAX_IN_VALUES = 100;
    public const int MAX_FILTER_VALUE_LENGTH = 200;
    private const int MAX_OFFSET = 2_147_483_647;

    /** @param array<string, list<Condition>> $filters */
    public function __construct(
        public int $page = 0,
        public int $size = 0,
        public ?string $sort = null,
        public array $filters = [],
    ) {}

    public function offset(): int
    {
        return $this->page * $this->size;
    }

    public function withDefaults(): self
    {
        return $this->size === 0 ? new self($this->page, self::DEFAULT_PAGE_SIZE, $this->sort, $this->filters) : $this;
    }

    /** Checks the structural limits: one request must not carry hundreds of predicates. */
    public function validate(): void
    {
        if ($this->page < 0) {
            throw ApiException::invalidField('page', 'page must not be negative');
        }
        if ($this->size < 1 || $this->size > self::MAX_PAGE_SIZE) {
            throw ApiException::invalidField('size', 'size must be between 1 and 100');
        }
        if ($this->page * $this->size > self::MAX_OFFSET) {
            throw ApiException::invalidField('page', 'page is too large');
        }
        if (count($this->filters) > self::MAX_FILTER_FIELDS) {
            throw ApiException::validation(ErrorCode::InvalidFilter, 'too many filter fields');
        }
        foreach ($this->filters as $field => $conditions) {
            if (count($conditions) > self::MAX_CONDITIONS_PER_FIELD) {
                throw ApiException::validation(ErrorCode::InvalidFilter, 'too many conditions on one field', ['field' => $field]);
            }
            foreach ($conditions as $condition) {
                if (!self::withinValueLimits($condition->value)) {
                    throw ApiException::validation(ErrorCode::InvalidFilter, 'filter value exceeds the allowed limits', ['field' => $field]);
                }
            }
        }
    }

    private static function withinValueLimits(mixed $value): bool
    {
        if (is_string($value)) {
            return mb_strlen($value) <= self::MAX_FILTER_VALUE_LENGTH;
        }
        if (is_array($value)) {
            if (count($value) > self::MAX_IN_VALUES) {
                return false;
            }
            foreach ($value as $item) {
                if (!self::withinValueLimits($item)) {
                    return false;
                }
            }
        }

        return true;
    }
}
