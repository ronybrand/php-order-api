<?php

declare(strict_types=1);

namespace App\Infrastructure\Postgres;

use App\Application\Condition;
use App\Application\SearchRequest;
use App\Domain\Error\ApiException;
use App\Domain\Error\ErrorCode;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Turns a validated SearchRequest into SQL fragments. Unknown filter fields are ignored (a client may send a
 * superset of what an entity knows); an unknown sort field is VALIDATION-06.
 */
final class SearchQueryBuilder
{
    private const array COMPARISONS = ['eq' => '=', 'neq' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];

    public function build(SearchEntity $entity, SearchRequest $request): SearchQuery
    {
        $where = ['deleted_at IS NULL'];
        $bindings = [];

        $filters = $request->filters;
        ksort($filters); // deterministic SQL: easier to test and to read in pg_stat_statements
        foreach ($filters as $name => $conditions) {
            $field = $entity->fields[$name] ?? null;
            if ($field === null) {
                continue;
            }
            foreach ($conditions as $condition) {
                [$clause, $values] = $this->condition($name, $field, $condition);
                $where[] = $clause;
                array_push($bindings, ...$values);
            }
        }

        return new SearchQuery(implode(' AND ', $where), $this->orderBy($entity, $request->sort), $bindings);
    }

    private function orderBy(SearchEntity $entity, ?string $sort): string
    {
        if ($sort === null || $sort === '') {
            return 'created_at DESC, id';
        }
        $descending = str_starts_with($sort, '-');
        $name = $descending ? substr($sort, 1) : $sort;
        $field = $entity->fields[$name] ?? null;
        if ($field === null) {
            throw ApiException::validation(ErrorCode::UnknownSort, 'cannot sort by this field', ['field' => $name, 'allowed' => $entity->names()]);
        }

        // id breaks ties so pages never overlap
        return $field->column . ($descending ? ' DESC' : ' ASC') . ', id';
    }

    /** @return array{string, list<mixed>} */
    private function condition(string $name, SearchField $field, Condition $condition): array
    {
        $op = strtolower($condition->op);
        $op = $op === '' ? 'eq' : $op;
        $reject = static fn(string $reason): ApiException => ApiException::validation(ErrorCode::InvalidFilter, $reason, ['field' => $name, 'op' => $op]);

        try {
            if (isset(self::COMPARISONS[$op])) {
                if ($op !== 'eq' && $op !== 'neq' && $field->type === FieldType::Enum) {
                    throw $reject('ordering operators are not supported on this field');
                }

                return ["{$field->column} " . self::COMPARISONS[$op] . ' ?', [$this->coerce($field, $condition->value)]];
            }

            return match ($op) {
                'in' => $this->inList($field, $condition->value, $reject),
                'between' => $this->between($field, $condition->value, $reject),
                'lk' => $this->like($field, $condition->value, $reject),
                default => throw $reject('unknown operator'),
            };
        } catch (InvalidArgumentException $e) {
            throw $reject($e->getMessage());
        }
    }

    /**
     * @param callable(string): ApiException $reject
     * @return array{string, list<mixed>}
     */
    private function inList(SearchField $field, mixed $value, callable $reject): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > SearchRequest::MAX_IN_VALUES) {
            throw $reject('in expects between 1 and 100 values');
        }
        $values = array_map(fn(mixed $v): mixed => $this->coerce($field, $v), $value);

        return ["{$field->column} IN (" . implode(', ', array_fill(0, count($values), '?')) . ')', $values];
    }

    /**
     * @param callable(string): ApiException $reject
     * @return array{string, list<mixed>}
     */
    private function between(SearchField $field, mixed $value, callable $reject): array
    {
        if ($field->type === FieldType::Enum) {
            throw $reject('ordering operators are not supported on this field');
        }
        if (!is_array($value) || !array_is_list($value) || count($value) !== 2) {
            throw $reject('between expects exactly 2 values');
        }

        return ["{$field->column} BETWEEN ? AND ?", [$this->coerce($field, $value[0]), $this->coerce($field, $value[1])]];
    }

    /**
     * @param callable(string): ApiException $reject
     * @return array{string, list<mixed>}
     */
    private function like(SearchField $field, mixed $value, callable $reject): array
    {
        if ($field->type !== FieldType::String) {
            throw $reject('lk is only supported on text fields');
        }
        if (!is_string($value)) {
            throw $reject('lk expects a string');
        }

        return ["{$field->column} ILIKE ? ESCAPE '\\'", ['%' . self::escapeLike($value) . '%']];
    }

    /** Neutralizes the LIKE wildcards: "50%" must match the text "50%", not everything starting with 50. */
    private static function escapeLike(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }

    /** Converts a decoded JSON value to the PHP type the driver expects for the column. */
    private function coerce(SearchField $field, mixed $value): mixed
    {
        return match ($field->type) {
            FieldType::String => is_string($value) ? $value : throw new InvalidArgumentException('expected a string'),
            FieldType::Uuid => is_string($value) && self::isUuid($value) ? strtolower($value) : throw new InvalidArgumentException('expected a UUID'),
            FieldType::Integer => self::toInt($value),
            FieldType::Time => self::toTime($value),
            FieldType::Enum => is_string($value) && in_array($value, $field->allowed, true)
                ? $value
                : throw new InvalidArgumentException('expected one of ' . implode(', ', $field->allowed)),
        };
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $value) === 1;
    }

    private static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && $value === floor($value) && abs($value) <= 2 ** 53) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^-?\d{1,18}$/D', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException('expected an integer');
    }

    private static function toTime(mixed $value): string
    {
        $utc = new DateTimeZone('UTC');
        if (is_string($value)) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
                $time = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $utc);
            } else {
                $time = DateTimeImmutable::createFromFormat(DATE_RFC3339_EXTENDED, $value);
                if ($time === false) {
                    $time = DateTimeImmutable::createFromFormat(DATE_RFC3339, $value);
                }
            }
            if ($time !== false) {
                return $time->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z');
            }
        }

        throw new InvalidArgumentException('expected an RFC 3339 timestamp or a YYYY-MM-DD date');
    }
}
