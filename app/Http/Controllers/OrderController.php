<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\OrderService;
use App\Application\SearchRequest;
use App\Http\Representations;
use App\Http\RequestContext;
use App\Http\Requests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final readonly class OrderController
{
    public function __construct(private OrderService $orders) {}

    public function create(Request $request): JsonResponse
    {
        [$customerId, $items] = Requests::newOrder($request);
        $order = $this->orders->create($customerId, $items, RequestContext::requireActor($request));

        return new JsonResponse(Representations::order($order), 201);
    }

    public function show(string $id): JsonResponse
    {
        return new JsonResponse(Representations::order($this->orders->get(Requests::uuid($id, 'id'))));
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->orders->delete(Requests::uuid($id, 'id'), RequestContext::requireActor($request));

        return new Response(status: 204);
    }

    public function addItem(Request $request, string $id): JsonResponse
    {
        $order = $this->orders->addItem(Requests::uuid($id, 'id'), Requests::itemFromRequest($request), RequestContext::requireActor($request));

        return new JsonResponse(Representations::order($order));
    }

    public function updateItem(Request $request, string $id, string $itemId): JsonResponse
    {
        $order = $this->orders->updateItemQuantity(
            Requests::uuid($id, 'id'),
            Requests::uuid($itemId, 'item_id'),
            Requests::quantity($request),
            RequestContext::requireActor($request),
        );

        return new JsonResponse(Representations::order($order));
    }

    public function removeItem(Request $request, string $id, string $itemId): JsonResponse
    {
        $order = $this->orders->removeItem(Requests::uuid($id, 'id'), Requests::uuid($itemId, 'item_id'), RequestContext::requireActor($request));

        return new JsonResponse(Representations::order($order));
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        return new JsonResponse(Representations::order($this->orders->confirm(Requests::uuid($id, 'id'), RequestContext::requireActor($request))));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return new JsonResponse(Representations::order($this->orders->cancel(Requests::uuid($id, 'id'), RequestContext::requireActor($request))));
    }

    public function searchByQuery(Request $request): JsonResponse
    {
        return $this->search(Requests::searchFromQuery($request));
    }

    public function searchByBody(Request $request): JsonResponse
    {
        return $this->search(Requests::searchFromBody($request));
    }

    private function search(SearchRequest $search): JsonResponse
    {
        return new JsonResponse(Representations::page($this->orders->search($search), Representations::order(...)));
    }
}
