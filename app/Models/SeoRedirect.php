<?php

namespace App\Models;

use App\Seo\SiteAddress;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An old address that forwards visitors and search engines to a new one. Made by itself when a
 * product or group gets a new web address ("auto"), by staff ("manual"), by the WHMCS pack
 * ("whmcs") or by an add-on ("addon"). It only acts when Nuvabill would show "page not found".
 *
 * @property string $from_path
 * @property string $to_path
 * @property int $hits
 * @property int|null $status
 * @property string|null $source
 * @property bool|null $keep_query
 * @property Carbon|null $last_hit_at
 */
class SeoRedirect extends Model
{
    /** The kinds of redirect staff can pick: moved for good, or moved for now. */
    public const STATUSES = [301, 302];

    /** How many redirects in a row are followed to find where an address finally goes. */
    public const MAX_CHAIN = 10;

    public const SOURCES = ['auto', 'manual', 'whmcs', 'addon'];

    /** Addresses Nuvabill itself uses, which never forward. The admin address is added to these. */
    private const RESERVED = ['api', 'install', 'webhooks', 'build', 'package-assets', 'storage', 'robots.txt', 'sitemap.xml'];

    private const SCHEME = '/^[a-z][a-z0-9+.-]*:/i';

    protected $fillable = ['from_path', 'to_path', 'hits', 'status', 'source', 'keep_query', 'last_hit_at'];

    protected function casts(): array
    {
        return ['hits' => 'integer', 'status' => 'integer', 'keep_query' => 'boolean', 'last_hit_at' => 'datetime'];
    }

    /**
     * Forward $from to $to. Addresses that forwarded to $from now go straight to $to, and $to
     * stops forwarding because it is in use again.
     */
    public static function remember(string $from, string $to): void
    {
        $from = self::normalise($from);
        $to = self::normalise($to);

        if ($from === $to || $from === '') {
            return;
        }

        self::query()->where('from_path', $to)->delete();
        self::query()->where('to_path', $from)->update(['to_path' => $to]);
        self::query()->updateOrCreate(['from_path' => $from], ['to_path' => $to]);
    }

    /**
     * Where an address that no longer exists should go, if it moved. The exact address wins; then
     * the same address in small letters, and with its %-codes read.
     */
    public static function target(string $path): ?self
    {
        $path = self::normalise($path);
        $decoded = self::normalise(rawurldecode($path));
        $variants = array_values(array_unique([$path, mb_strtolower($path), $decoded, mb_strtolower($decoded)]));
        $rows = self::query()->whereIn('from_path', $variants)->get();

        foreach ($variants as $variant) {
            $row = $rows->first(fn (self $row): bool => $row->from_path === $variant);

            if ($row !== null) {
                return $row;
            }
        }

        // MySQL and MariaDB compare without letter case, so a row can match in another case.
        return $rows->first();
    }

    /**
     * The full address this redirect sends visitors to. Always on this site, even for an older
     * saved row that looks like a full address: url() would keep that unchanged.
     */
    public function targetUrl(): string
    {
        $path = ltrim((string) $this->to_path, '/');

        return $path === '' ? url('/') : rtrim(url('/'), '/').'/'.$path;
    }

    public static function normalise(string $path): string
    {
        return mb_substr(trim($path, '/'), 0, 191);
    }

    /**
     * A same-site target, or null when the address leaves the site or is not valid. Returns
     * "path?query" without the first slash ('' = home).
     */
    public static function cleanTarget(string $input): ?string
    {
        $target = self::targetOnThisSite($input);

        return $target !== null && mb_strlen($target) <= 191 ? $target : null;
    }

    /**
     * Save a redirect made by staff, the WHMCS pack or an add-on. Chains are shortened: when the
     * target forwards too, the final address is saved.
     *
     * @return array{0: ?self, 1: ?string, 2: ?string} the saved redirect, or null, the reason (plain English, translated) and the field it is about ("from" or "to")
     */
    public static function saveManual(string $from, string $to, int $status, bool $keepQuery, string $source = 'manual', ?self $existing = null): array
    {
        [$fromPath, $reason] = self::cleanSource($from);

        if ($fromPath === null) {
            return [null, $reason, 'from'];
        }

        $target = self::targetOnThisSite($to);

        if ($target === null) {
            return [null, __('Use an address on this site.'), 'to'];
        }

        if (mb_strlen($target) > 191) {
            return [null, __('The address is too long.'), 'to'];
        }

        if (self::samePath(self::pathOf($target), $fromPath)) {
            return [null, __('The address would send visitors to itself.'), 'to'];
        }

        $taken = self::query()->where('from_path', $fromPath)
            ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->getKey()))
            ->exists();

        if ($taken) {
            return [null, __('Another redirect already uses this old address.'), 'from'];
        }

        $final = self::finalTarget($target, $fromPath);

        if ($final === null) {
            return [null, __('This would send visitors in a circle.'), 'to'];
        }

        $redirect = DB::transaction(function () use ($existing, $fromPath, $final, $status, $keepQuery, $source): self {
            $redirect = $existing ?? new self(['hits' => 0]);
            $redirect->fill([
                'from_path' => $fromPath,
                'to_path' => $final,
                'status' => in_array($status, self::STATUSES, true) ? $status : 301,
                'keep_query' => $keepQuery,
                'source' => in_array($source, self::SOURCES, true) ? $source : 'manual',
            ])->save();

            // Addresses that forwarded to this old address now go straight to the end.
            self::query()->where('to_path', $fromPath)->whereKeyNot($redirect->getKey())->update(['to_path' => $final]);

            return $redirect;
        });

        return [$redirect, null, null];
    }

    /**
     * Where a path finally goes after following other redirects; null when it loops.
     */
    public static function finalTarget(string $to, string $from): ?string
    {
        $current = $to;

        for ($step = 0; $step <= self::MAX_CHAIN; $step++) {
            $path = self::pathOf($current);

            if (self::samePath($path, $from)) {
                return null;
            }

            $next = $path === '' ? null : self::target($path);

            if ($next === null) {
                return $current;
            }

            // The next redirect keeps the part after ? of this one, unless it has its own.
            $query = str_contains($current, '?') ? substr($current, (int) strpos($current, '?')) : '';
            $current = str_contains($next->to_path, '?') ? $next->to_path : $next->to_path.$query;
        }

        return null;
    }

    /**
     * "path?query" on this site without the first slash, of any length, or null.
     */
    private static function targetOnThisSite(string $input): ?string
    {
        $value = self::withoutFragment(trim($input));

        if ($value === null || $value === '') {
            return null;
        }

        if (preg_match(self::SCHEME, $value)) {
            $value = self::pathOnThisSite($value);

            if ($value === null) {
                return null;
            }
        } elseif (str_starts_with($value, '//')) {
            return null;
        } elseif (! str_starts_with($value, '/')) {
            $value = '/'.$value;
        }

        [$path, $query] = array_pad(explode('?', $value, 2), 2, null);
        $path = trim($path, '/');

        // "/https://other.site" or "/javascript:…" would lose the first slash and leave the site.
        if (preg_match(self::SCHEME, $path)) {
            return null;
        }

        return $path.($query !== null && $query !== '' ? '?'.$query : '');
    }

    /**
     * The old address staff typed, as it is saved, or null and the reason.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function cleanSource(string $input): array
    {
        $value = self::withoutFragment(trim($input));

        if ($value !== null && preg_match(self::SCHEME, $value)) {
            $value = self::pathOnThisSite($value);
        } elseif ($value !== null && str_starts_with($value, '//')) {
            $value = null;
        }

        if ($value === null) {
            return [null, __('Use an address on this site.')];
        }

        if (str_contains($value, '?')) {
            return [null, __('Leave out the part after ?')];
        }

        $path = trim($value, '/');

        if (mb_strlen($path) > 191) {
            return [null, __('The address is too long.')];
        }

        if (self::isReserved($path)) {
            return [null, __('Nuvabill uses this address.')];
        }

        return [$path, null];
    }

    /**
     * The home page and addresses Nuvabill itself uses.
     */
    private static function isReserved(string $path): bool
    {
        $path = mb_strtolower($path);

        if ($path === '') {
            return true;
        }

        foreach ([...self::RESERVED, mb_strtolower(trim((string) config('nuvabill.admin_path'), '/'))] as $prefix) {
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix.'/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The address without "#…", or null when it has control characters, spaces, a backslash or a
     * user name ("@" before the first "/").
     */
    private static function withoutFragment(string $value): ?string
    {
        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1F\x7F\s\\\\]/u', $value)) {
            return null;
        }

        $value = explode('#', $value, 2)[0];
        $rest = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $value);
        $at = strpos($rest, '@');
        $slash = strpos($rest, '/');

        if ($at !== false && ($slash === false || $at < $slash)) {
            return null;
        }

        return $value;
    }

    /**
     * "/path?query" of a full address on this site, or null when it is on another site or not
     * http(s). The site's own folder (when Nuvabill runs in one) is left out.
     */
    private static function pathOnThisSite(string $url): ?string
    {
        $parts = parse_url($url);
        $root = parse_url(SiteAddress::root()) ?: [];

        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || ($parts['host'] ?? '') === '' || strtolower($parts['host']) !== strtolower($root['host'] ?? '')) {
            return null;
        }

        $path = '/'.ltrim($parts['path'] ?? '', '/');
        $folder = rtrim($root['path'] ?? '', '/');

        if ($folder !== '' && ($path === $folder || str_starts_with($path, $folder.'/'))) {
            $path = '/'.ltrim(substr($path, strlen($folder)), '/');
        }

        return $path.(isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '');
    }

    private static function pathOf(string $target): string
    {
        return trim(explode('?', $target, 2)[0], '/');
    }

    private static function samePath(string $a, string $b): bool
    {
        return mb_strtolower(trim($a, '/')) === mb_strtolower(trim($b, '/'));
    }
}
