<?php

use App\Http\Controllers\Marketplace\MediaController;
use App\Http\Controllers\Marketplace\StoreApiController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Marketplace store API
|--------------------------------------------------------------------------
|
| Only on the marketplace store. Every copy of Nuvabill calls these to list
| the catalog, download packages and check license keys. No sessions.
|
*/

Route::prefix('api/marketplace/v1')->name('marketplace.api.')->middleware(SubstituteBindings::class)->group(function (): void {
    Route::get('catalog', [StoreApiController::class, 'catalog'])->middleware('throttle:120,1')->name('catalog');
    Route::post('download', [StoreApiController::class, 'download'])->middleware('throttle:30,1')->name('download');
    Route::post('licenses/check', [StoreApiController::class, 'check'])->middleware('throttle:60,1')->name('licenses.check');
    Route::get('files/{version}/{license}', [StoreApiController::class, 'file'])
        ->whereNumber(['version', 'license'])
        ->middleware(['signed', 'throttle:30,1'])
        ->name('file');
});

Route::get('marketplace-media/{item}/{file}', MediaController::class)
    ->where('item', '[a-z0-9][a-z0-9_-]*')
    ->where('file', '[A-Za-z0-9_.-]+')
    ->name('marketplace.media');
