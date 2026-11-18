<?php

namespace Tests\Fixtures\HomePage;

use App\Extensions\Addons\Addon;
use App\Seo\Seo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Shows the home page and one visitor page, for the tests.
 */
class HomePageAddon extends Addon
{
    public function publicRoutes(): void
    {
        Route::get('hello', fn (): string => 'Hello from the add-on')->name('hello');
        // Translated into German only (0.7.4 language contract).
        Route::get('ready-page', function (): string {
            app(Seo::class)->allowIndexing()->setReadyLanguages(['de']);

            return '<!DOCTYPE html><html><head><title>x</title></head><body>Ready page</body></html>';
        })->name('ready-page');
        // A copy of the hello page.
        Route::get('copy-page', function (): string {
            app(Seo::class)->allowIndexing()->setCanonicalPath('/hello');

            return '<!DOCTYPE html><html><head><title>x</title></head><body>Copy page</body></html>';
        })->name('copy-page');
        Route::get('{page}', fn (string $page): string => 'Add-on page '.$page)->where('page', '[a-z0-9-]+')->name('page');
    }

    /**
     * Never registered: the manifest does not ask for the "webhook" permission.
     */
    public function webhookRoutes(): void
    {
        Route::post('ping', fn (): string => 'should not be reachable')->name('ping');
    }

    public function servesHomePage(): bool
    {
        return true;
    }

    public function homePage(Request $request): string
    {
        return 'Add-on home page';
    }

    public function sitemapPages(): array
    {
        return [
            ['path' => 'hello', 'updated' => null],
            ['path' => 'ready-page', 'updated' => null, 'languages' => ['de', 'xx']],
        ];
    }
}
