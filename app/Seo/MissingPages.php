<?php

namespace App\Seo;

use App\Models\SeoMissingPage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Addresses people could not open ("page not found"), so staff can see old links that still get
 * visits and send them somewhere with a redirect. Only the address, a count and two dates are
 * kept: no IP address, referrer or browser. Files such as pictures and scripts are left out.
 */
class MissingPages
{
    /** At most this many addresses are kept; the least visited go first. */
    public const MAX_ROWS = 1000;

    /** How many new visits are counted each minute, for the whole site. */
    private const PER_MINUTE = 120;

    private const SKIPPED = ['api', 'build', 'package-assets', '.well-known'];

    private const FILE_TYPES = '/\.(css|js|map|png|jpe?g|gif|svg|ico|webp|avif|woff2?|txt|xml)$/i';

    public static function record(Request $request): void
    {
        if (! $request->isMethod('GET')) {
            return;
        }

        $path = self::cleanPath($request->path());

        if ($path === null) {
            return;
        }

        RateLimiter::attempt('seo-missing-pages', self::PER_MINUTE, fn () => self::count($path), 60);
    }

    /**
     * The most visited addresses, most recent first when the counts are the same.
     *
     * @return Collection<int, SeoMissingPage>
     */
    public static function top(int $limit = 20): Collection
    {
        return SeoMissingPage::query()->orderByDesc('hits')->orderByDesc('last_seen_at')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * The address as it is kept, or null when it is not counted.
     */
    public static function cleanPath(string $path): ?string
    {
        $path = trim(rawurldecode(trim($path, '/')), '/');
        $path = mb_scrub((string) preg_replace('/[\x00-\x1F\x7F]/u', '', mb_scrub($path, 'UTF-8')), 'UTF-8');
        $path = mb_substr($path, 0, 191);
        $lower = mb_strtolower($path);
        $adminPath = mb_strtolower(trim((string) config('nuvabill.admin_path'), '/'));

        if ($path === '' || preg_match(self::FILE_TYPES, $path)) {
            return null;
        }

        foreach ([...self::SKIPPED, $adminPath] as $prefix) {
            if ($prefix !== '' && ($lower === $prefix || str_starts_with($lower, $prefix.'/'))) {
                return null;
            }
        }

        return $path;
    }

    private static function count(string $path): void
    {
        $now = now();

        if (SeoMissingPage::query()->where('path', $path)->increment('hits', 1, ['last_seen_at' => $now]) > 0) {
            return;
        }

        try {
            $page = SeoMissingPage::query()->create(['path' => $path, 'hits' => 1, 'first_seen_at' => $now, 'last_seen_at' => $now]);
        } catch (UniqueConstraintViolationException) {
            // Another visit added it a moment ago.
            SeoMissingPage::query()->where('path', $path)->increment('hits', 1, ['last_seen_at' => $now]);

            return;
        }

        if (SeoMissingPage::query()->count() > self::MAX_ROWS) {
            $oldest = SeoMissingPage::query()->whereKeyNot($page->getKey())
                ->orderBy('hits')->orderBy('last_seen_at')->orderBy('id')
                ->limit(100)->pluck('id');
            SeoMissingPage::query()->whereKey($oldest)->delete();
        }
    }
}
