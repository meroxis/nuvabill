<?php

namespace App\Seo;

use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * The picture apps like WhatsApp, Facebook, X and LinkedIn show when someone shares a link to
 * the store. Kept outside the public folder and sent by SeoController, so it works without a
 * storage link on shared hosting.
 */
class ShareImage
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    public static function store(UploadedFile $file): string
    {
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $name = 'share-'.Str::lower(Str::random(10)).'.'.$extension;

        if (! is_dir(self::folder())) {
            mkdir(self::folder(), 0755, true);
        }

        $file->move(self::folder(), $name);
        self::remove();
        app(Settings::class)->set('seo.share_image', $name);

        return $name;
    }

    public static function remove(): void
    {
        $old = self::name();

        if ($old !== null) {
            @unlink(self::path($old));
        }

        app(Settings::class)->set('seo.share_image', null);
    }

    /**
     * The saved file name, when the file is still there.
     */
    public static function name(): ?string
    {
        $name = setting('seo.share_image');

        return is_string($name) && self::isValidName($name) && is_file(self::path($name)) ? $name : null;
    }

    public static function isValidName(string $name): bool
    {
        return (bool) preg_match('/^share-[a-z0-9]{10}\.(jpg|png|webp)$/', $name);
    }

    public static function path(string $name): string
    {
        return self::folder().DIRECTORY_SEPARATOR.$name;
    }

    public static function url(): ?string
    {
        $name = self::name();

        return $name === null ? null : SiteAddress::url('share-image/'.$name);
    }

    /**
     * @return array{0: int, 1: int}|null Width and height.
     */
    public static function size(): ?array
    {
        $name = self::name();
        $size = $name === null ? false : @getimagesize(self::path($name));

        return $size === false ? null : [(int) $size[0], (int) $size[1]];
    }

    private static function folder(): string
    {
        return storage_path('app/seo');
    }
}
