<?php

namespace Tests\Fixtures\Hooks;

use App\Extensions\Addons\Addon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Receives posts from another service, for the tests.
 */
class HooksAddon extends Addon
{
    public function webhookRoutes(): void
    {
        Route::post('ping/{key}', fn (Request $request, string $key): array => ['key' => $key, 'got' => $request->json('text')])->name('ping');
    }
}
