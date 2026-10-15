<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Customer;
use App\Domain\CustomerInput;
use App\Domain\Error\ApiException;
use App\Domain\Error\ErrorCode;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The customer use cases. Authorization (who may call what) is decided by the HTTP layer; the rules here are
 * the ones that hold for every caller.
 */
final readonly class CustomerService
{
    public function __construct(
        private Store $store,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    /** Registers a customer, rejecting a duplicate tax id (CONFLICT-01) or passport (CONFLICT-02). */
    public function create(CustomerInput $input, Actor $actor): Customer
    {
        $customer = Customer::create($input, $actor->id, $this->clock->now());
        $repository = $this->store->repos()->customers;
        // The unique indexes are the real guarantee under concurrency: this check only gives the common case a
        // readable error without a failed write, and the repository maps a violation to the same codes.
        $this->ensureUnique($repository, $customer, null);
        $repository->create($customer);
        $this->logger->info('customer created', ['customer_id' => $customer->id, 'actor' => $actor->id]);

        return $customer;
    }

    public function get(string $id): Customer
    {
        return $this->store->repos()->customers->get($id);
    }

    /** Replaces a customer's data, validating uniqueness against the other customers. */
    public function update(string $id, CustomerInput $input, Actor $actor): Customer
    {
        $repository = $this->store->repos()->customers;
        $customer = $repository->get($id);
        $customer->replace($input, $actor->id, $this->clock->now());
        $this->ensureUnique($repository, $customer, $id);
        $repository->update($customer);
        $this->logger->info('customer updated', ['customer_id' => $id, 'actor' => $actor->id]);

        return $customer;
    }

    /** Soft-deletes a customer that has no active orders (CONFLICT-03 otherwise). */
    public function delete(string $id, Actor $actor): void
    {
        $this->store->inTransaction(function (Repositories $repos) use ($id, $actor): void {
            // FOR UPDATE first: an order being created for this customer holds the shared lock, so the check below
            // sees it, and one that starts afterwards waits for this transaction and then finds the customer gone.
            $repos->customers->lockForUpdate($id);
            if ($repos->orders->hasActive($id)) {
                throw ApiException::conflict(ErrorCode::CustomerHasOrders, 'customer has orders and cannot be deleted', ['id' => $id]);
            }
            $repos->customers->softDelete($id, $actor->id, $this->clock->now());
        });
        $this->logger->info('customer deleted', ['customer_id' => $id, 'actor' => $actor->id]);
    }

    /** @return Page<Customer> */
    public function search(SearchRequest $request): Page
    {
        $request = $request->withDefaults();
        $request->validate();

        return $this->store->repos()->customers->search($request);
    }

    /** Returns CONFLICT-01/02 without echoing the value: tax id and passport are PII. */
    private function ensureUnique(CustomerRepository $repository, Customer $customer, ?string $except): void
    {
        if ($repository->taxIdTaken($customer->taxId, $except)) {
            throw ApiException::conflict(ErrorCode::DuplicateTaxId, 'a customer with this tax_id already exists');
        }
        if ($customer->passportNumber !== null && $repository->passportTaken($customer->passportNumber, $except)) {
            throw ApiException::conflict(ErrorCode::DuplicatePassport, 'a customer with this passport_number already exists');
        }
    }
}
