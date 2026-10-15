<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\Condition;
use App\Application\SearchRequest;
use App\Domain\CustomerInput;
use App\Domain\Error\ApiException;
use App\Domain\ItemInput;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;
use stdClass;

/** Turns HTTP requests into the typed inputs of the use cases. All failures are VALIDATION-01. */
final class Requests
{
    public static function customer(Request $request): CustomerInput
    {
        $payload = Payload::fromRequest($request, ['name', 'tax_id', 'passport_number', 'email']);

        return new CustomerInput(
            $payload->string('name'),
            $payload->string('tax_id'),
            $payload->optionalString('passport_number'),
            $payload->string('email'),
        );
    }

    /** @return array{string, list<ItemInput>} */
    public static function newOrder(Request $request): array
    {
        $payload = Payload::fromRequest($request, ['customer_id', 'items']);
        $items = array_map(
            static fn(mixed $item): ItemInput => self::item($item),
            $payload->list('items'),
        );

        return [self::uuid($payload->string('customer_id'), 'customer_id'), $items];
    }

    public static function item(mixed $raw): ItemInput
    {
        if (!$raw instanceof stdClass) {
            throw ApiException::invalidField('items', 'each item must be an object');
        }

        return self::itemFrom(Payload::fromObject($raw, ['description', 'unit_price', 'quantity']));
    }

    public static function itemFromRequest(Request $request): ItemInput
    {
        return self::itemFrom(Payload::fromRequest($request, ['description', 'unit_price', 'quantity']));
    }

    public static function quantity(Request $request): int
    {
        return Payload::fromRequest($request, ['quantity'])->int('quantity');
    }

    public static function uuid(string $value, string $field): string
    {
        if (!Uuid::isValid($value) || strlen($value) !== 36) {
            throw ApiException::invalidField($field, "{$field} must be a valid UUID");
        }

        return strtolower($value);
    }

    /** GET search: page, size and sort only; filters need the POST form. */
    public static function searchFromQuery(Request $request): SearchRequest
    {
        $integer = static function (string $name) use ($request): int {
            $raw = $request->query($name);
            if ($raw === null) {
                return 0;
            }
            if (!is_string($raw) || preg_match('/^-?\d{1,18}$/D', $raw) !== 1) {
                throw ApiException::invalidField($name, "{$name} must be an integer");
            }

            return (int) $raw;
        };
        $sort = $request->query('sort');
        if ($sort !== null && !is_string($sort)) {
            throw ApiException::invalidField('sort', 'sort must be a string');
        }

        return new SearchRequest($integer('page'), $integer('size'), $sort);
    }

    public static function searchFromBody(Request $request): SearchRequest
    {
        $payload = Payload::fromRequest($request, ['page', 'size', 'sort', 'filters']);
        $filters = [];
        foreach (get_object_vars($payload->object('filters') ?? new stdClass()) as $field => $conditions) {
            if (!is_array($conditions) || !array_is_list($conditions)) {
                throw ApiException::invalidField('filters', 'each filter must be a list of conditions');
            }
            $filters[(string) $field] = array_map(static fn(mixed $c): Condition => self::condition($c), $conditions);
        }

        return new SearchRequest($payload->int('page', 0), $payload->int('size', 0), $payload->optionalString('sort'), $filters);
    }

    private static function condition(mixed $raw): Condition
    {
        if (!$raw instanceof stdClass) {
            throw ApiException::invalidField('filters', 'each condition must be an object');
        }
        $condition = Payload::fromObject($raw, ['op', 'value']);
        $value = $condition->raw('value');
        if ($value instanceof stdClass) {
            throw ApiException::invalidField('filters', 'a condition value must be a scalar or a list');
        }

        return new Condition($condition->has('op') ? $condition->string('op') : 'eq', $value);
    }

    private static function itemFrom(Payload $payload): ItemInput
    {
        try {
            $price = BigDecimal::of($payload->decimal('unit_price'));
        } catch (MathException) {
            throw ApiException::invalidField('unit_price', 'unit_price must be a number');
        }

        return new ItemInput($payload->string('description'), $price, $payload->int('quantity'));
    }
}
