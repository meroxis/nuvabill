<?php

namespace App\Seo;

use App\Support\Locales;

/**
 * The one address search engines should use for each page: the site address from .env (so
 * www and non-www, or a second domain, count as one site), with ?lang= for other languages.
 */
class SiteAddress
{
    public static function root(): string
    {
        $configured = rtrim((string) config('app.url'), '/');

        return self::isReal($configured) ? $configured : rtrim(request()->root(), '/');
    }

    /**
     * Whether the address could be reached from the internet: not localhost, a .test name or an IP
     * address on this computer.
     */
    public static function isReal(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== ''
            && ! in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
            && ! preg_match('/\.(test|local|localhost|invalid|example)$/', $host)
            && ! in_array($host, ['example.com', 'example.org', 'example.net'], true);
    }

    /**
     * The full address of a path, in a language when it is not the site's default one, and on a
     * page of a list after the first.
     */
    public static function url(string $path, ?string $locale = null, ?int $page = null): string
    {
        $path = trim($path, '/');
        $url = self::root().($path === '' ? '/' : '/'.$path);
        $query = http_build_query(array_filter([
            'lang' => $locale !== null && $locale !== Locales::default() ? $locale : null,
            'page' => $page !== null && $page > 1 ? $page : null,
        ], fn (mixed $value): bool => $value !== null));

        return $query === '' ? $url : $url.'?'.$query;
    }
}
