<?php

namespace App\Seo;

use App\Support\Locales;

/**
 * The one address search engines should use for each page: the site address from .env (so
 * www and non-www, or a second domain, count as one site), with ?lang= for other languages.
 */
class SiteAddress
{
    /**
     * The site address without a slash at the end, such as https://site.com or
     * https://site.com/billing. Never ends in /index.php: on a real server a request for
     * /index.php?rp=... comes in through index.php, and Laravel's own root and url() then end
     * in /index.php.
     */
    public static function root(): string
    {
        $configured = rtrim((string) config('app.url'), '/');

        if (self::isReal($configured)) {
            return $configured;
        }

        $request = request();

        return rtrim($request->getSchemeAndHttpHost().$request->getBasePath(), '/');
    }

    /**
     * The full address of a path on this site, with the part after ? as it is, for redirects.
     * The path always comes after the site address, so it can never lead to another site. The
     * home page has no slash at the end.
     */
    public static function to(string $path, string $query = ''): string
    {
        $path = trim($path, '/');
        $url = self::root().($path === '' ? '' : '/'.$path);

        return $query === '' ? $url : $url.'?'.$query;
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
