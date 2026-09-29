<?php

use App\Auth\Social\SocialLogin;
use App\Http\Controllers\Client\AccountController;
use App\Http\Controllers\Client\AffiliateController;
use App\Http\Controllers\Client\Auth\LoginController;
use App\Http\Controllers\Client\Auth\PasskeyLoginController;
use App\Http\Controllers\Client\Auth\PasswordResetController;
use App\Http\Controllers\Client\Auth\RegisterController;
use App\Http\Controllers\Client\Auth\SocialLoginController;
use App\Http\Controllers\Client\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Client\DashboardController;
use App\Http\Controllers\Client\DomainController;
use App\Http\Controllers\Client\InvoiceController;
use App\Http\Controllers\Client\PasskeyController;
use App\Http\Controllers\Client\PaymentController;
use App\Http\Controllers\Client\QuoteController;
use App\Http\Controllers\Client\ServiceController;
use App\Http\Controllers\Client\TicketController;
use App\Http\Controllers\Client\TwoFactorController;
use App\Http\Controllers\Client\WalletController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\PreviewController;
use App\Http\Controllers\Store\CartController;
use App\Http\Controllers\Store\CheckoutController;
use App\Http\Controllers\Store\DomainSearchController;
use App\Http\Controllers\Store\QuickOrderController;
use App\Http\Controllers\Store\StoreController;
use App\Http\Controllers\WhatsAppConnectController;
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
Route::post('cart/coupon', [CartController::class, 'coupon'])->middleware('throttle:15,1')->name('cart.coupon');
Route::delete('cart/{index}', [CartController::class, 'destroy'])->whereNumber('index')->name('cart.destroy');

Route::post('language', [LanguageController::class, 'client'])->middleware('throttle:30,1')->name('language.update');

// WhatsApp connect page. It only works on the Nuvabill store, where Meta's app keys are set.
Route::get('connect/whatsapp', [WhatsAppConnectController::class, 'show'])->name('connect.whatsapp');
Route::get('connect/whatsapp/status', [WhatsAppConnectController::class, 'status'])->middleware('throttle:60,1')->name('connect.whatsapp.status');
Route::post('connect/whatsapp/exchange', [WhatsAppConnectController::class, 'exchange'])->middleware('throttle:10,1')->name('connect.whatsapp.exchange');

Route::get('preview/stop', [PreviewController::class, 'stop'])->name('preview.stop');
Route::get('preview/{kind}/{slug}', [PreviewController::class, 'start'])->whereIn('kind', ['theme', 'orderform'])->where('slug', '[a-z0-9][a-z0-9_-]*')->name('preview.start');

Route::get('store-api/domains', [QuickOrderController::class, 'domains'])->middleware('throttle:30,1')->name('store.api.domains');
Route::post('store-api/quote', [QuickOrderController::class, 'quote'])->middleware('throttle:120,1')->name('store.api.quote');
Route::post('order', [QuickOrderController::class, 'store'])->middleware(['throttle:10,1', 'client.active', 'client.two-factor', 'captcha:checkout'])->name('order.store');

Route::get('checkout', [CheckoutController::class, 'show'])->name('checkout.show');
Route::post('checkout', [CheckoutController::class, 'store'])->middleware(['auth:web', 'client.active', 'client.two-factor', 'captcha:checkout'])->name('checkout.store');

/*
|--------------------------------------------------------------------------
| Client sign in
|--------------------------------------------------------------------------
*/

Route::middleware('guest:web')->group(function (): void {
    Route::get('login', [LoginController::class, 'create'])->name('client.login');
    Route::post('login', [LoginController::class, 'store'])->middleware(['throttle:10,1', 'captcha:client_login']);
    Route::get('two-factor', [TwoFactorChallengeController::class, 'create'])->name('client.two-factor.challenge');
    Route::post('two-factor', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:6,1');
    Route::post('two-factor/resend', [TwoFactorChallengeController::class, 'resend'])->middleware('throttle:3,1')->name('client.two-factor.resend');
    Route::post('passkey/options', [PasskeyLoginController::class, 'options'])->middleware('throttle:20,1')->name('client.passkey.options');
    Route::post('passkey', [PasskeyLoginController::class, 'store'])->middleware('throttle:10,1')->name('client.passkey.login');
    Route::get('register', [RegisterController::class, 'create'])->name('client.register');
    Route::post('register', [RegisterController::class, 'store'])->middleware(['throttle:5,1', 'captcha:client_register']);
    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('client.password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'email'])->middleware(['throttle:5,1', 'captcha:password_reset'])->name('client.password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('client.password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])->name('client.password.update');
});

Route::post('logout', [LoginController::class, 'destroy'])->middleware('auth:web')->name('client.logout');

Route::middleware('throttle:20,1')->whereIn('provider', array_keys(SocialLogin::PROVIDERS))->group(function (): void {
    Route::get('auth/{provider}/redirect', [SocialLoginController::class, 'redirect'])->name('client.social.redirect');
    Route::get('auth/{provider}/callback', [SocialLoginController::class, 'callback'])->name('client.social.callback');
});

/*
|--------------------------------------------------------------------------
| Client area
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:web', 'client.active', 'client.two-factor'])->prefix('client')->name('client.')->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('services', [ServiceController::class, 'index'])->name('services.index');
    Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');
    Route::post('services/{service}/login', [ServiceController::class, 'login'])->name('services.login');
    Route::post('services/{service}/panel/{action}', [ServiceController::class, 'panel'])->where('action', '[a-z0-9-]+')->middleware('throttle:20,1')->name('services.panel');

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
    Route::get('invoices/{invoice}/payment-status', [PaymentController::class, 'status'])->middleware('throttle:30,1')->name('invoices.payment-status');
    Route::post('invoices/{invoice}/pay-from-wallet', [WalletController::class, 'pay'])->middleware('throttle:10,1')->name('invoices.wallet');

    Route::get('quotes', [QuoteController::class, 'index'])->name('quotes.index');
    Route::get('quotes/{quote}', [QuoteController::class, 'show'])->name('quotes.show');
    Route::get('quotes/{quote}/pdf', [QuoteController::class, 'pdf'])->name('quotes.pdf');
    Route::post('quotes/{quote}/accept', [QuoteController::class, 'accept'])->middleware('throttle:10,1')->name('quotes.accept');
    Route::post('quotes/{quote}/decline', [QuoteController::class, 'decline'])->middleware('throttle:10,1')->name('quotes.decline');

    Route::get('affiliate', [AffiliateController::class, 'show'])->name('affiliate');
    Route::post('affiliate', [AffiliateController::class, 'join'])->middleware('throttle:5,1')->name('affiliate.join');
    Route::post('affiliate/withdraw', [AffiliateController::class, 'withdraw'])->middleware('throttle:5,1')->name('affiliate.withdraw');

    Route::get('wallet', [WalletController::class, 'show'])->name('wallet');
    Route::post('wallet', [WalletController::class, 'store'])->middleware('throttle:10,1')->name('wallet.store');

    Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('tickets/new', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('tickets', [TicketController::class, 'store'])->middleware(['throttle:10,1', 'captcha:tickets'])->name('tickets.store');
    Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::post('tickets/{ticket}/reply', [TicketController::class, 'reply'])->middleware('throttle:20,1')->name('tickets.reply');
    Route::post('tickets/{ticket}/close', [TicketController::class, 'close'])->name('tickets.close');

    Route::get('account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('account', [AccountController::class, 'update'])->name('account.update');
    Route::put('account/password', [AccountController::class, 'password'])->name('account.password');
    Route::post('account/two-factor/app', [TwoFactorController::class, 'startApp'])->name('account.two-factor.app');
    Route::post('account/two-factor/app/confirm', [TwoFactorController::class, 'confirmApp'])->middleware('throttle:6,1')->name('account.two-factor.app.confirm');
    Route::post('account/two-factor/email', [TwoFactorController::class, 'startEmail'])->middleware('throttle:3,1')->name('account.two-factor.email');
    Route::post('account/two-factor/email/confirm', [TwoFactorController::class, 'confirmEmail'])->middleware('throttle:6,1')->name('account.two-factor.email.confirm');
    Route::delete('account/two-factor', [TwoFactorController::class, 'destroy'])->name('account.two-factor.destroy');
    Route::post('account/passkeys/options', [PasskeyController::class, 'options'])->middleware('throttle:6,1')->name('account.passkeys.options');
    Route::post('account/passkeys', [PasskeyController::class, 'store'])->middleware('throttle:10,1')->name('account.passkeys.store');
    Route::delete('account/passkeys/{passkey}', [PasskeyController::class, 'destroy'])->name('account.passkeys.destroy');
    Route::delete('account/chat/{chatLink}', [AccountController::class, 'disconnectChat'])->name('account.chat.destroy');
    Route::delete('account/social/{provider}', [SocialLoginController::class, 'destroy'])->whereIn('provider', array_keys(SocialLogin::PROVIDERS))->name('account.social.destroy');
});
