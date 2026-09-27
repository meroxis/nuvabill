<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceItem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Screenshots and icons of marketplace items, stored privately and served with a long cache.
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

        $path = self::path($item, $file);
        $type = self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
        abort_if($type === null || ! is_file($path), 404);

        return response()->file($path, ['Content-Type' => $type, 'Cache-Control' => 'public, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }

    public static function path(string $item, string $file = ''): string
    {
        return rtrim(storage_path('app/private/marketplace/media/'.$item.'/'.$file), '/');
    }
}
