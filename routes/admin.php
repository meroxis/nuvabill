<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\Auth\PasswordResetController;
use App\Http\Controllers\Admin\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\CurrencyController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\DomainController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\GatewayController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\MarketplaceController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ProductAddonController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductGroupController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\RegistrarController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\ServerController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SocialLoginController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\TaxController;
use App\Http\Controllers\Admin\TicketController;
use App\Http\Controllers\Admin\TldPriceController;
use App\Http\Controllers\Admin\UpdateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin area
|--------------------------------------------------------------------------
|
| Loaded with the "admin." name prefix under the path set in config/nuvabill.php.
|
*/

Route::middleware('guest:admin')->group(function (): void {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware(['throttle:6,1', 'captcha:admin_login']);
    Route::get('two-factor', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    Route::post('two-factor', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:6,1');
    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'email'])->middleware(['throttle:3,1', 'captcha:password_reset'])->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])->name('password.update');
});

Route::middleware(['auth:admin', 'admin.can', 'admin.two-factor'])->group(function (): void {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile/password', [ProfileController::class, 'password'])->name('profile.password');
    Route::post('profile/two-factor', [ProfileController::class, 'startTwoFactor'])->name('profile.two-factor.start');
    Route::post('profile/two-factor/confirm', [ProfileController::class, 'confirmTwoFactor'])->name('profile.two-factor.confirm');
    Route::delete('profile/two-factor', [ProfileController::class, 'disableTwoFactor'])->name('profile.two-factor.disable');

    Route::middleware('admin.can:clients.view')->group(function (): void {
        Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
        Route::get('clients/{client}', [ClientController::class, 'show'])->whereNumber('client')->name('clients.show');
    });

    Route::middleware('admin.can:clients.manage')->group(function (): void {
        Route::get('clients/create', [ClientController::class, 'create'])->name('clients.create');
        Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
        Route::get('clients/{client}/edit', [ClientController::class, 'edit'])->name('clients.edit');
        Route::put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');
        Route::delete('clients/{client}/two-factor', [ClientController::class, 'resetTwoFactor'])->name('clients.two-factor.destroy');
    });

    Route::middleware('admin.can:orders.manage')->group(function (): void {
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{order}/accept', [OrderController::class, 'accept'])->name('orders.accept');
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
    });

    Route::middleware('admin.can:services.manage')->group(function (): void {
        Route::get('services', [ServiceController::class, 'index'])->name('services.index');
        Route::get('services/{service}', [ServiceController::class, 'show'])->name('services.show');
        Route::put('services/{service}', [ServiceController::class, 'update'])->name('services.update');
        Route::post('services/{service}/module/{action}', [ServiceController::class, 'module'])
            ->whereIn('action', ['create', 'suspend', 'unsuspend', 'terminate', 'change-package'])
            ->name('services.module');
    });

    Route::middleware('admin.can:domains.manage')->group(function (): void {
        Route::get('domains', [DomainController::class, 'index'])->name('domains.index');
        Route::get('domains/{domain}', [DomainController::class, 'show'])->name('domains.show');
        Route::put('domains/{domain}', [DomainController::class, 'update'])->name('domains.update');
        Route::post('domains/{domain}/{action}', [DomainController::class, 'action'])
            ->whereIn('action', ['register', 'renew', 'sync', 'nameservers', 'invoice'])
            ->name('domains.action');
    });

    Route::middleware('admin.can:billing.view')->group(function (): void {
        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice')->name('invoices.show');
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    });

    Route::middleware('admin.can:billing.manage')->group(function (): void {
        Route::get('invoices/create', [InvoiceController::class, 'create'])->name('invoices.create');
        Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::post('clients/{client}/wallet', [ClientController::class, 'wallet'])->name('clients.wallet');
        Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])->name('invoices.payments.store');
        Route::post('invoices/{invoice}/refund', [InvoiceController::class, 'refund'])->name('invoices.refund');
        Route::post('invoices/{invoice}/publish', [InvoiceController::class, 'publish'])->name('invoices.publish');
        Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
        Route::post('invoices/{invoice}/email', [InvoiceController::class, 'email'])->name('invoices.email');
    });

    Route::middleware('admin.can:coupons.manage')->group(function (): void {
        Route::resource('coupons', CouponController::class)->except('show');
    });

    Route::middleware('admin.can:support.manage')->group(function (): void {
        Route::get('tickets', [TicketController::class, 'index'])->name('tickets.index');
        Route::get('tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
        Route::post('tickets/{ticket}/reply', [TicketController::class, 'reply'])->name('tickets.reply');
        Route::post('tickets/{ticket}/close', [TicketController::class, 'close'])->name('tickets.close');
    });

    Route::middleware('admin.can:products.manage')->group(function (): void {
        Route::resource('products', ProductController::class)->except('show');
        Route::resource('product-addons', ProductAddonController::class)->except('show')->parameters(['product-addons' => 'productAddon']);
        Route::resource('product-groups', ProductGroupController::class)->except(['index', 'show'])->parameters(['product-groups' => 'productGroup']);
        Route::resource('servers', ServerController::class)->except('show');
        Route::post('servers/{server}/test', [ServerController::class, 'test'])->name('servers.test');
    });

    Route::middleware('admin.can:settings.manage')->prefix('settings')->name('settings.')->group(function (): void {
        Route::get('/', [SettingsController::class, 'edit'])->name('edit');
        Route::put('/', [SettingsController::class, 'update'])->name('update');
        Route::put('mail', [SettingsController::class, 'updateMail'])->name('mail');
        Route::post('mail/test', [SettingsController::class, 'testMail'])->name('mail.test');

        Route::get('currencies', [CurrencyController::class, 'edit'])->name('currencies.edit');
        Route::put('currencies', [CurrencyController::class, 'update'])->name('currencies.update');

        Route::get('taxes', [TaxController::class, 'index'])->name('taxes.index');
        Route::put('taxes', [TaxController::class, 'settings'])->name('taxes.settings');
        Route::post('taxes/rules', [TaxController::class, 'store'])->name('taxes.store');
        Route::put('taxes/rules/{taxRule}', [TaxController::class, 'update'])->name('taxes.update');
        Route::delete('taxes/rules/{taxRule}', [TaxController::class, 'destroy'])->name('taxes.destroy');

        Route::get('gateways', [GatewayController::class, 'index'])->name('gateways.index');
        Route::get('gateways/{gateway}', [GatewayController::class, 'edit'])->name('gateways.edit');
        Route::put('gateways/{gateway}', [GatewayController::class, 'update'])->name('gateways.update');

        Route::get('domains', [TldPriceController::class, 'index'])->name('tlds.index');
        Route::post('domains', [TldPriceController::class, 'store'])->name('tlds.store');
        Route::put('domains/settings', [TldPriceController::class, 'settings'])->name('tlds.settings');
        Route::get('domains/{tldPrice}/edit', [TldPriceController::class, 'edit'])->name('tlds.edit');
        Route::put('domains/{tldPrice}', [TldPriceController::class, 'update'])->name('tlds.update');
        Route::delete('domains/{tldPrice}', [TldPriceController::class, 'destroy'])->name('tlds.destroy');

        Route::get('registrars', [RegistrarController::class, 'index'])->name('registrars.index');
        Route::get('registrars/{registrar}', [RegistrarController::class, 'edit'])->name('registrars.edit');
        Route::put('registrars/{registrar}', [RegistrarController::class, 'update'])->name('registrars.update');
        Route::post('registrars/{registrar}/test', [RegistrarController::class, 'test'])->middleware('throttle:10,1')->name('registrars.test');

        Route::get('email-templates', [EmailTemplateController::class, 'index'])->name('email-templates.index');
        Route::get('email-templates/{emailTemplate}', [EmailTemplateController::class, 'edit'])->name('email-templates.edit');
        Route::put('email-templates/{emailTemplate}', [EmailTemplateController::class, 'update'])->name('email-templates.update');

        Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::put('departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');

        Route::get('activity', ActivityController::class)->name('activity');

        Route::get('import', [ImportController::class, 'index'])->name('import.index');
        Route::put('import', [ImportController::class, 'update'])->middleware('throttle:10,1')->name('import.update');
        Route::post('import/start', [ImportController::class, 'start'])->name('import.start');
        Route::post('import/cancel', [ImportController::class, 'cancel'])->name('import.cancel');

        Route::get('social-login', [SocialLoginController::class, 'edit'])->name('social.edit');
        Route::put('social-login', [SocialLoginController::class, 'update'])->name('social.update');

        Route::get('security', [SecurityController::class, 'edit'])->name('security.edit');
        Route::put('security', [SecurityController::class, 'update'])->name('security.update');
        Route::post('security/captcha-check', [SecurityController::class, 'checkCaptcha'])->middleware('throttle:10,1')->name('security.captcha-check');
    });

    Route::middleware('admin.can:staff.manage')->prefix('settings')->name('settings.')->group(function (): void {
        Route::resource('staff', StaffController::class)->except(['show', 'destroy'])->parameters(['staff' => 'admin']);
        Route::resource('roles', RoleController::class)->except(['show', 'destroy']);
    });

    Route::middleware('admin.can:marketplace.manage')->prefix('marketplace')->name('marketplace.')->group(function (): void {
        Route::get('/', [MarketplaceController::class, 'index'])->name('index');
        Route::get('{slug}', [MarketplaceController::class, 'show'])->name('show');
        Route::post('{slug}/install', [MarketplaceController::class, 'install'])->middleware('throttle:10,1')->name('install');
        Route::delete('{slug}', [MarketplaceController::class, 'destroy'])->name('destroy');
        Route::post('{slug}/activate', [MarketplaceController::class, 'activate'])->name('activate');
        Route::post('{slug}/deactivate', [MarketplaceController::class, 'deactivate'])->name('deactivate');
        Route::put('{slug}/license', [MarketplaceController::class, 'license'])->middleware('throttle:10,1')->name('license');
        Route::get('{slug}/settings', [MarketplaceController::class, 'settings'])->name('settings');
        Route::put('{slug}/settings', [MarketplaceController::class, 'saveSettings'])->name('settings.update');
    })->where(['slug' => '[a-z0-9][a-z0-9_-]*']);

    Route::middleware('admin.can:system.update')->prefix('updates')->name('updates.')->group(function (): void {
        Route::get('/', [UpdateController::class, 'index'])->name('index');
        Route::post('check', [UpdateController::class, 'check'])->name('check');
        Route::post('install', [UpdateController::class, 'install'])->name('install');
        Route::get('finish', [UpdateController::class, 'finish'])->name('finish');
        Route::put('settings', [UpdateController::class, 'settings'])->name('settings');
    });
});
