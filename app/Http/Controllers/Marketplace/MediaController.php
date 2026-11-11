<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceItem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Screenshots and icons of marketplace items, stored privately and served with a long cache.
 * Only the screenshots of a live listing are public; new ones wait for a reviewer, and staff
 * open them through their own signed-in link (Admin\ItemController::media()).
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
        // An item that is not live yet, or was hidden, shows its screenshots to staff only.
        abort_unless($record->isLive(), 404);
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

        $response = response()->file($path, ['Content-Type' => $type, 'Cache-Control' => $cache, 'X-Content-Type-Options' => 'nosniff']);

        // A file response marks itself as public. Images for staff only must not be.
        return str_contains($cache, 'private') ? $response->setPrivate() : $response;
    }

    public static function path(string $item, string $file = ''): string
    {
        return rtrim(storage_path('app/private/marketplace/media/'.$item.'/'.$file), '/');
    }
}
