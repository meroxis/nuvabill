<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceItem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Screenshots and icons of marketplace items, stored privately and served with a long cache.
 * Only the screenshots of the live listing are public; new ones wait for a reviewer.
 */
class MediaController extends Controller
{
    /**
     * @var array<string, string>
     */
    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];

    public function __invoke(string $item, string $file): BinaryFileResponse
    {
        $record = MarketplaceItem::query()->where('slug', $item)->firstOrFail();
        abort_unless(in_array($file, array_map('basename', (array) ($record->screenshots ?? [])), true), 404);

        return self::send($item, $file, 'public, max-age=86400');
    }

    /**
     * Send one image from an item's media folder, or a 404 when it is missing or not an image.
     */
    public static function send(string $item, string $file, string $cache): BinaryFileResponse
    {
        $path = self::path($item, basename($file));
        $type = self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
        abort_if($type === null || ! is_file($path), 404);

        return response()->file($path, ['Content-Type' => $type, 'Cache-Control' => $cache, 'X-Content-Type-Options' => 'nosniff']);
    }

    public static function path(string $item, string $file = ''): string
    {
        return rtrim(storage_path('app/private/marketplace/media/'.$item.'/'.$file), '/');
    }
}
