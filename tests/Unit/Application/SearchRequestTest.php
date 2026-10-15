<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\Condition;
use App\Application\CustomerService;
use App\Application\OrderService;
use App\Application\SearchRequest;
use App\Domain\Error\ApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Tests\Support\InMemoryStore;

final class SearchRequestTest extends TestCase
{
    /** @return array<string, array{SearchRequest}> */
    public static function invalidRequests(): array
    {
        $long = str_repeat('x', SearchRequest::MAX_FILTER_VALUE_LENGTH + 1);
        $manyFields = [];
        for ($i = 0; $i <= SearchRequest::MAX_FILTER_FIELDS; ++$i) {
            $manyFields['f' . $i] = [new Condition('eq', 'x')];
        }

        return [
            'negative page' => [new SearchRequest(-1, 10)],
            'size below one' => [new SearchRequest(0, -5)],
            'size above the max' => [new SearchRequest(0, SearchRequest::MAX_PAGE_SIZE + 1)],
            'offset overflow' => [new SearchRequest(2_147_483_647, 100)],
            'too many fields' => [new SearchRequest(0, 10, null, $manyFields)],
            'too many conditions' => [new SearchRequest(0, 10, null, ['name' => array_fill(0, SearchRequest::MAX_CONDITIONS_PER_FIELD + 1, new Condition('eq', 'x'))])],
            'value too long' => [new SearchRequest(0, 10, null, ['name' => [new Condition('eq', $long)]])],
            'long value in a list' => [new SearchRequest(0, 10, null, ['name' => [new Condition('in', ['a', $long])]])],
            'list above the limit' => [new SearchRequest(0, 10, null, ['name' => [new Condition('in', array_fill(0, SearchRequest::MAX_IN_VALUES + 1, 'a'))]])],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function test_both_services_reject_a_request_beyond_the_limits(SearchRequest $request): void
    {
        $clock = new MockClock('2026-10-14 12:00:00');
        $customers = new CustomerService(new InMemoryStore(), $clock, new NullLogger());
        $orders = new OrderService(new InMemoryStore(), null, $clock, new NullLogger());

        foreach ([fn() => $customers->search($request), fn() => $orders->search($request)] as $search) {
            try {
                $search();
                self::fail('expected a validation error');
            } catch (ApiException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_size_defaults_when_omitted_and_the_limits_are_accepted_exactly(): void
    {
        $customers = new CustomerService(new InMemoryStore(), new MockClock('2026-10-14 12:00:00'), new NullLogger());

        $defaulted = $customers->search(new SearchRequest());
        $atLimit = $customers->search(new SearchRequest(0, SearchRequest::MAX_PAGE_SIZE));

        self::assertSame(SearchRequest::DEFAULT_PAGE_SIZE, $defaulted->size);
        self::assertSame(SearchRequest::MAX_PAGE_SIZE, $atLimit->size);
    }

    public function test_the_offset_is_page_times_size(): void
    {
        self::assertSame(40, (new SearchRequest(2, 20))->offset());
    }
}
