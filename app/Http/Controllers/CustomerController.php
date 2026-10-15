<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\CustomerService;
use App\Http\Representations;
use App\Http\RequestContext;
use App\Http\Requests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final readonly class CustomerController
{
    public function __construct(private CustomerService $customers) {}

    public function create(Request $request): JsonResponse
    {
        $customer = $this->customers->create(Requests::customer($request), RequestContext::requireActor($request));

        return new JsonResponse(Representations::customer($customer), 201);
    }

    public function show(string $id): JsonResponse
    {
        return new JsonResponse(Representations::customer($this->customers->get(Requests::uuid($id, 'id'))));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $customer = $this->customers->update(Requests::uuid($id, 'id'), Requests::customer($request), RequestContext::requireActor($request));

        return new JsonResponse(Representations::customer($customer));
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->customers->delete(Requests::uuid($id, 'id'), RequestContext::requireActor($request));

        return new Response(status: 204);
    }

    public function searchByQuery(Request $request): JsonResponse
    {
        return $this->search(Requests::searchFromQuery($request));
    }

    public function searchByBody(Request $request): JsonResponse
    {
        return $this->search(Requests::searchFromBody($request));
    }

    private function search(\App\Application\SearchRequest $search): JsonResponse
    {
        return new JsonResponse(Representations::page($this->customers->search($search), Representations::customer(...)));
    }
}
