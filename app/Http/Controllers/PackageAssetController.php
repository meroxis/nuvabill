<?php

namespace App\Http\Controllers;

use App\Extensions\ExtensionManager;
use App\Support\Themes;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the public files of themes, order forms and extensions (CSS, scripts, fonts and images)
 * from their "public" folder. Packages from the marketplace are not built with Vite, so this is
 * how their files reach the browser. Only known file types inside that folder are served.
 */
class PackageAssetController extends Controller
{
    /**
     * @var array<string, string>
     */
    private const TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'json' => 'application/json',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'ttf' => 'font/ttf',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
    ];

    public function __invoke(Request $request, Themes $themes, ExtensionManager $extensions, string $kind, string $slug, string $path): BinaryFileResponse
    {
        $base = match ($kind) {
            'themes' => $themes->exists($slug) ? $themes->path($slug) : null,
            'orderforms' => $themes->orderFormExists($slug) ? $themes->orderFormPath($slug) : null,
            'extensions' => $extensions->find($slug)?->path,
            default => null,
        };

        abort_if($base === null, 404);

        $root = realpath($base.DIRECTORY_SEPARATOR.'public');
        $file = $root === false ? false : realpath($root.DIRECTORY_SEPARATOR.$path);
        $type = self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;

        abort_if($file === false || $type === null || ! is_file($file) || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR), 404);

        return response()->file($file, [
            'Content-Type' => $type,
            'Cache-Control' => $request->has('v') ? 'public, max-age=31536000, immutable' : 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The address of a package file, with its change time added so browsers fetch new versions.
     */
    public static function url(string $kind, string $slug, string $path): string
    {
        $base = match ($kind) {
            'themes' => app(Themes::class)->path($slug),
            'orderforms' => app(Themes::class)->orderFormPath($slug),
            default => (string) app(ExtensionManager::class)->find($slug)?->path,
        };

        $time = @filemtime($base.'/public/'.$path);

        return route('package.asset', ['kind' => $kind, 'slug' => $slug, 'path' => $path]).($time ? '?v='.$time : '');
    }
}
