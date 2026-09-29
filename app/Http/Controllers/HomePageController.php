<?php

namespace App\Http\Controllers;

use App\Extensions\ExtensionManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * The home page when a switched-on add-on, such as a website builder, shows its own instead of
 * the store. The store then lives at /store.
 */
class HomePageController extends Controller
{
    public function __invoke(Request $request, ExtensionManager $extensions): mixed
    {
        $addon = $extensions->homePageAddon();

        if ($addon === null) {
            return redirect()->route('store.index');
        }

        return $addon->homePage($request);
    }

    /**
     * The home page and the store's first page. Decided when the routes load, so switching the
     * add-on on or off takes effect on the next request.
     */
    public static function routes(): void
    {
        $addonHome = (bool) rescue(fn (): bool => app(ExtensionManager::class)->homePageAddon() !== null, false, report: false);

        if ($addonHome) {
            Route::get('/', self::class)->name('home');
            Route::get('store', [Store\StoreController::class, 'index'])->name('store.index');

            return;
        }

        Route::get('/', [Store\StoreController::class, 'index'])->name('store.index');
        Route::redirect('store', '/', 302);
    }
}
