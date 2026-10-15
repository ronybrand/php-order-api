<?php

declare(strict_types=1);

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\OrderController;
use App\Http\Middleware\AuthenticateBearer;
use App\Http\Middleware\LimitRequestsPerUser;
use App\Http\Middleware\RequireRole;
use App\Auth\Claims;
use Illuminate\Support\Facades\Route;

// The only public route. Every other route is registered inside the authenticated group below: a route is
// never open by omission.
Route::get('/health', HealthController::class);

Route::middleware([AuthenticateBearer::class, LimitRequestsPerUser::class])->group(function (): void {
    $admin = RequireRole::class . ':' . Claims::ROLE_ADMIN;

    // Static "search" routes come before the "{id}" ones so they are never read as an id.
    Route::post('/customers', [CustomerController::class, 'create'])->middleware($admin);
    Route::get('/customers/search', [CustomerController::class, 'searchByQuery']);
    Route::post('/customers/search', [CustomerController::class, 'searchByBody']);
    Route::get('/customers/{id}', [CustomerController::class, 'show']);
    Route::put('/customers/{id}', [CustomerController::class, 'update'])->middleware($admin);
    Route::delete('/customers/{id}', [CustomerController::class, 'destroy'])->middleware($admin);

    Route::post('/orders', [OrderController::class, 'create']);
    Route::get('/orders/search', [OrderController::class, 'searchByQuery']);
    Route::post('/orders/search', [OrderController::class, 'searchByBody']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::delete('/orders/{id}', [OrderController::class, 'destroy']);
    Route::post('/orders/{id}/items', [OrderController::class, 'addItem']);
    Route::patch('/orders/{id}/items/{itemId}', [OrderController::class, 'updateItem']);
    Route::delete('/orders/{id}/items/{itemId}', [OrderController::class, 'removeItem']);
    Route::post('/orders/{id}/confirm', [OrderController::class, 'confirm']);
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);
});
