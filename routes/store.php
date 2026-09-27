<?php

use App\Http\Controllers\Marketplace\Admin\DeveloperController as AdminDeveloperController;
use App\Http\Controllers\Marketplace\Admin\ItemController as AdminItemController;
use App\Http\Controllers\Marketplace\Admin\LicenseController as AdminLicenseController;
use App\Http\Controllers\Marketplace\Admin\PayoutController;
use App\Http\Controllers\Marketplace\Admin\ReviewController;
use App\Http\Controllers\Marketplace\DeveloperController;
use App\Http\Controllers\Marketplace\DeveloperItemController;
use App\Http\Controllers\Marketplace\LicenseController;
use App\Http\Controllers\Marketplace\MarketplacePageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Marketplace store pages
|--------------------------------------------------------------------------
|
| Only on the marketplace store: the public marketplace, the developer
| portal in the client area, and the review and payout pages for staff.
|
*/

Route::middleware('web')->group(function (): void {
    Route::get('marketplace', [MarketplacePageController::class, 'index'])->name('marketplace.index');
    Route::get('marketplace/{item}', [MarketplacePageController::class, 'show'])->name('marketplace.show');
    Route::get('developers', [MarketplacePageController::class, 'developers'])->name('marketplace.developers');

    Route::middleware(['auth:web', 'client.active', 'client.two-factor'])->group(function (): void {
        Route::post('client/licenses/{license}/move', [LicenseController::class, 'move'])->middleware('throttle:5,1')->name('client.licenses.move');

        Route::prefix('developer')->name('developer.')->group(function (): void {
            Route::get('join', [DeveloperController::class, 'create'])->name('join');
            Route::post('join', [DeveloperController::class, 'store'])->middleware('throttle:5,1')->name('join.store');
            Route::get('/', [DeveloperController::class, 'dashboard'])->name('dashboard');
            Route::put('profile', [DeveloperController::class, 'update'])->name('profile');

            Route::get('items/new', [DeveloperItemController::class, 'create'])->name('items.create');
            Route::post('items', [DeveloperItemController::class, 'store'])->middleware('throttle:10,1')->name('items.store');
            Route::get('items/{item}', [DeveloperItemController::class, 'show'])->name('items.show');
            Route::put('items/{item}', [DeveloperItemController::class, 'update'])->name('items.update');
            Route::post('items/{item}/versions', [DeveloperItemController::class, 'upload'])->middleware('throttle:10,1')->name('items.versions.store');
            Route::post('versions/{version}/messages', [DeveloperItemController::class, 'message'])->middleware('throttle:20,1')->name('versions.messages.store');
        });
    });

    Route::middleware(['auth:admin', 'admin.can:marketplace.review', 'admin.two-factor'])
        ->prefix(config('nuvabill.admin_path').'/store')
        ->name('admin.store.')
        ->group(function (): void {
            Route::get('reviews', [ReviewController::class, 'index'])->name('reviews.index');
            Route::get('reviews/{version}', [ReviewController::class, 'show'])->name('reviews.show');
            Route::get('reviews/{version}/download', [ReviewController::class, 'download'])->name('reviews.download');
            Route::put('reviews/{version}/checklist', [ReviewController::class, 'checklist'])->name('reviews.checklist');
            Route::post('reviews/{version}/approve', [ReviewController::class, 'approve'])->name('reviews.approve');
            Route::post('reviews/{version}/changes', [ReviewController::class, 'changes'])->name('reviews.changes');
            Route::post('reviews/{version}/reject', [ReviewController::class, 'reject'])->name('reviews.reject');

            Route::get('items', [AdminItemController::class, 'index'])->name('items.index');
            Route::get('items/{item}/edit', [AdminItemController::class, 'edit'])->name('items.edit');
            Route::put('items/{item}', [AdminItemController::class, 'update'])->name('items.update');

            Route::get('developers', [AdminDeveloperController::class, 'index'])->name('developers.index');
            Route::get('developers/{developer}/edit', [AdminDeveloperController::class, 'edit'])->name('developers.edit');
            Route::put('developers/{developer}', [AdminDeveloperController::class, 'update'])->name('developers.update');

            Route::get('licenses', [AdminLicenseController::class, 'index'])->name('licenses.index');
            Route::get('licenses/{license}', [AdminLicenseController::class, 'show'])->name('licenses.show');
            Route::post('licenses/{license}/revoke', [AdminLicenseController::class, 'revoke'])->name('licenses.revoke');
            Route::post('licenses/{license}/restore', [AdminLicenseController::class, 'restore'])->name('licenses.restore');
            Route::post('licenses/{license}/release', [AdminLicenseController::class, 'release'])->name('licenses.release');

            Route::get('payouts', [PayoutController::class, 'index'])->name('payouts.index');
            Route::post('payouts', [PayoutController::class, 'store'])->name('payouts.store');
            Route::put('commission', [PayoutController::class, 'commission'])->name('commission');
        });
});
