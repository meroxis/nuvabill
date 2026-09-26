<?php

use App\Http\Controllers\Client\AccountController;
use App\Http\Controllers\Client\Auth\LoginController;
use App\Http\Controllers\Client\Auth\PasswordResetController;
use App\Http\Controllers\Client\Auth\RegisterController;
use App\Http\Controllers\Client\DashboardController;
use App\Http\Controllers\Client\DomainController;
use App\Http\Controllers\Client\InvoiceController;
use App\Http\Controllers\Client\PaymentController;
use App\Http\Controllers\Client\ServiceController;
use App\Http\Controllers\Client\TicketController;
use App\Http\Controllers\Store\CartController;
use App\Http\Controllers\Store\CheckoutController;
use App\Http\Controllers\Store\DomainSearchController;
use App\Http\Controllers\Store\StoreController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Store (public)
|--------------------------------------------------------------------------
*/

Route::get('/', [StoreController::class, 'index'])->name('store.index');
Route::get('store/{group}', [StoreController::class, 'group'])->name('store.group');
Route::get('store/{group}/{product}', [StoreController::class, 'product'])->name('store.product')->scopeBindings();

Route::get('domains', DomainSearchController::class)->middleware('throttle:30,1')->name('store.domains');

Route::get('cart', [CartController::class, 'show'])->name('cart.show');
Route::post('cart', [CartController::class, 'store'])->middleware('throttle:30,1')->name('cart.store');
Route::post('cart/domains', [CartController::class, 'storeDomain'])->middleware('throttle:30,1')->name('cart.domains.store');
Route::delete('cart/{index}', [CartController::class, 'destroy'])->whereNumber('index')->name('cart.destroy');

Route::get('checkout', [CheckoutController::class, 'show'])->name('checkout.show');
Route::post('checkout', [CheckoutController::class, 'store'])->middleware(['auth:web', 'client.active'])->name('checkout.store');

/*
|--------------------------------------------------------------------------
| Client sign in
|--------------------------------------------------------------------------
*/

Route::middleware('guest:web')->group(function (): void {
    Route::get('login', [LoginController::class, 'create'])->name('client.login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:10,1');
    Route::get('register', [RegisterController::class, 'create'])->name('client.register');
    Route::post('register', [RegisterController::class, 'store'])->middleware('throttle:5,1');
    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('client.password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('client.password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('client.password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])->name('client.password.update');
});

Route::post('logout', [LoginController::class, 'destroy'])->middleware('auth:web')->name('client.logout');

/*
|--------------------------------------------------------------------------
| Client area
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:web', 'client.active'])->prefix('client')->name('client.')->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('services', [ServiceController::class, 'index'])->name('services.index');
    Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');
    Route::post('services/{service}/login', [ServiceController::class, 'login'])->name('services.login');

    Route::get('domains', [DomainController::class, 'index'])->name('domains.index');
    Route::get('domains/{domain}', [DomainController::class, 'show'])->name('domains.show');
    Route::put('domains/{domain}/nameservers', [DomainController::class, 'nameservers'])->middleware('throttle:10,1')->name('domains.nameservers');
    Route::put('domains/{domain}/auto-renew', [DomainController::class, 'autoRenew'])->name('domains.auto-renew');
    Route::post('domains/{domain}/renew', [DomainController::class, 'renew'])->name('domains.renew');

    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::post('invoices/{invoice}/pay', [PaymentController::class, 'store'])->name('invoices.pay');
    Route::get('invoices/{invoice}/return/{gateway}', [PaymentController::class, 'return'])->name('invoices.return');

    Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('tickets/new', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('tickets', [TicketController::class, 'store'])->middleware('throttle:10,1')->name('tickets.store');
    Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('tickets/{ticket}/reply', [TicketController::class, 'reply'])->middleware('throttle:20,1')->name('tickets.reply');
    Route::post('tickets/{ticket}/close', [TicketController::class, 'close'])->name('tickets.close');

    Route::get('account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('account', [AccountController::class, 'update'])->name('account.update');
    Route::put('account/password', [AccountController::class, 'password'])->name('account.password');
});
