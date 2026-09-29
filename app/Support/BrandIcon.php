<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * The site's icon: the browser tab icon, the icon next to the company name in the admin area and
 * client area, and the phone app icon. It is the Nuvabill icon, unless a site with a White-label
 * License uploads its own square PNG. The file is kept outside the public folder and sent by
 * BrandIconController, so it works without a storage link on shared hosting.
 */
class BrandIcon
{
    /**
     * The smallest size, in pixels, of an uploaded icon: phones want 512 × 512 for the app icon.
     */
    public const MIN_SIZE = 512;

    public static function store(UploadedFile $file): string
    {
        $name = 'icon-'.Str::lower(Str::random(10)).'.png';

        if (! is_dir(self::folder())) {
            mkdir(self::folder(), 0755, true);
        }

        $file->move(self::folder(), $name);
        self::remove();
        app(Settings::class)->set('branding.icon', $name);

        return $name;
    }

    public static function remove(): void
    {
        $old = self::name();

        if ($old !== null) {
            @unlink(self::path($old));
        }

        app(Settings::class)->set('branding.icon', null);
    }

    /**
     * The uploaded file's name, when the file is still there, whether or not it is in use.
     */
    public static function name(): ?string
    {
        $name = setting('branding.icon');

        return is_string($name) && self::isValidName($name) && is_file(self::path($name)) ? $name : null;
    }

    public static function isValidName(string $name): bool
    {
        return (bool) preg_match('/^icon-[a-z0-9]{10}\.png$/', $name);
    }

    public static function path(string $name): string
    {
        return self::folder().DIRECTORY_SEPARATOR.$name;
    }

    /**
     * Whether pages show the uploaded icon: only while the White-label License is valid.
     */
    public static function inUse(): bool
    {
        return self::name() !== null && rescue(fn (): bool => app(WhiteLabel::class)->isActive(), false, report: false);
    }

    /**
     * The address of the uploaded icon, while it is in use.
     */
    public static function url(): ?string
    {
        return self::inUse() ? route('brand.icon', self::name()) : null;
    }

    /**
     * @return array{0: int, 1: int}|null Width and height of the uploaded icon.
     */
    public static function size(): ?array
    {
        $name = self::name();
        $size = $name === null ? false : @getimagesize(self::path($name));

        return $size === false ? null : [(int) $size[0], (int) $size[1]];
    }

    /**
     * The icon tags a page's head does not have yet: the browser tab icon and the one phones use
     * when the page is added to the home screen.
     */
    public static function headTags(string $head): string
    {
        $has = fn (string $rel): bool => (bool) preg_match('/<link[^>]+rel=["\']?(shortcut )?'.$rel.'["\'\s>]/i', $head);
        $url = self::url();
        $tags = [];

        if (! $has('icon')) {
            $tags[] = $url !== null
                ? '<link rel="icon" type="image/png" href="'.e($url).'">'
                : '<link rel="icon" href="'.e(asset('favicon.ico')).'" sizes="32x32">'."\n".'<link rel="icon" href="'.e(asset('favicon.svg')).'" type="image/svg+xml">';
        }

        if (! $has('apple-touch-icon')) {
            $tags[] = '<link rel="apple-touch-icon" href="'.e(self::touchIconUrl()).'">';
        }

        return implode("\n", $tags);
    }

    /**
     * The icon phones show for the site on their home screen.
     */
    public static function touchIconUrl(): string
    {
        return self::url() ?? asset('images/app/apple-touch-icon.png');
    }

    private static function folder(): string
    {
        return storage_path('app/brand');
    }
}
