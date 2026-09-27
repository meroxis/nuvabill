<?php

use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\TicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| REST API, version 1 (/api/v1)
|--------------------------------------------------------------------------
|
| Every request sends "Authorization: Bearer nb_…" with a key made under Your profile → API keys.
| A key acts as its staff member, so the same role permissions apply as in the admin area.
|
*/

Route::get('me', [CatalogController::class, 'me'])->name('me');
Route::get('products', [CatalogController::class, 'products'])->name('products.index');

Route::middleware('admin.can:clients.view')->group(function (): void {
    Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
    Route::get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
});
Route::post('clients', [ClientController::class, 'store'])->middleware('admin.can:clients.manage')->name('clients.store');

Route::middleware('admin.can:services.manage')->group(function (): void {
    Route::get('services', [ServiceController::class, 'index'])->name('services.index');
    Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');
    Route::post('services/{service}/{action}', [ServiceController::class, 'action'])->whereIn('action', ['suspend', 'unsuspend', 'terminate'])->name('services.action');
});

Route::middleware('admin.can:billing.view')->group(function (): void {
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
});
Route::middleware('admin.can:billing.manage')->group(function (): void {
    Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'payment'])->name('invoices.payments');
});

Route::get('orders', [CatalogController::class, 'orders'])->middleware('admin.can:orders.manage')->name('orders.index');

Route::middleware('admin.can:support.manage')->group(function (): void {
    Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('tickets/{ticket}/replies', [TicketController::class, 'reply'])->name('tickets.replies');
});
