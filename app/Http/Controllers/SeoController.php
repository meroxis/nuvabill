<?php

namespace App\Http\Controllers;

use App\Seo\RobotsTxt;
use App\Seo\ShareImage;
use App\Seo\Sitemap;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * robots.txt, sitemap.xml and the share image. No session or cookies: search engines and link
 * preview apps fetch these.
 */
class SeoController extends Controller
{
    public function robots(RobotsTxt $robots): Response
    {
        return response($robots->content(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function sitemap(Sitemap $sitemap): Response
    {
        abort_unless(setting('seo.sitemap') && setting('seo.visible'), 404);

        return response($sitemap->xml(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function shareImage(string $name): BinaryFileResponse
    {
        abort_unless($name === ShareImage::name(), 404);

        return response()->file(ShareImage::path($name), [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
