<?php

namespace Tests\Fixtures\HomePage;

use App\Extensions\Addons\Addon;
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
        Route::get('{page}', fn (string $page): string => 'Add-on page '.$page)->where('page', '[a-z0-9-]+')->name('page');
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
        return [['path' => 'hello', 'updated' => null]];
    }
}
