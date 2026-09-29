<?php

namespace App\Http\Controllers;

use App\Support\BrandIcon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sends the site's own icon while it is in use. The name changes with every upload, so browsers
 * may keep it for a year.
 */
class BrandIconController extends Controller
{
    public function __invoke(string $name): BinaryFileResponse
    {
        abort_unless(BrandIcon::inUse() && $name === BrandIcon::name(), 404);

        return response()->file(BrandIcon::path($name), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
