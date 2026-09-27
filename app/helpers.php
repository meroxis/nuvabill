<?php

use App\Http\Controllers\PackageAssetController;
use App\Support\Money;
use App\Support\Settings;

if (! function_exists('setting')) {
    /**
     * Read a system setting, falling back to the built-in default.
     */
    function setting(string $key, mixed $default = null): mixed
    {
        return app(Settings::class)->get($key, $default);
    }
}

if (! function_exists('money')) {
    /**
     * Format minor units (cents) as a currency string, for example 1250 => "$12.50".
     */
    function money(int $minor, ?string $currency = null): string
    {
        return Money::format($minor, $currency ?? setting('billing.currency'));
    }
}

if (! function_exists('package_asset')) {
    /**
     * The address of a file in a theme, order form or extension's public folder,
     * for example package_asset('themes', 'aurora', 'aurora.css').
     */
    function package_asset(string $kind, string $slug, string $path): string
    {
        return PackageAssetController::url($kind, $slug, $path);
    }
}
