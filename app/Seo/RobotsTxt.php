<?php

namespace App\Seo;

/**
 * robots.txt: which parts of the site search engines should leave alone, and where the sitemap
 * is. The admin address is never listed, so it stays hidden; admin pages say "noindex" instead.
 */
class RobotsTxt
{
    /**
     * Private parts of the site: the client area, cart, checkout and sign-in steps.
     */
    public const PRIVATE_PATHS = ['/client', '/cart', '/checkout', '/developer', '/preview/', '/auth/', '/two-factor', '/reset-password/'];

    public function content(): string
    {
        $prefix = rtrim((string) parse_url(SiteAddress::root(), PHP_URL_PATH), '/');
        $lines = ['# Made by Nuvabill. Change it in Settings → Search engines.', 'User-agent: *'];

        if (! setting('seo.visible')) {
            $lines[] = 'Disallow: /';

            return implode("\n", $lines)."\n";
        }

        foreach (self::PRIVATE_PATHS as $path) {
            $lines[] = 'Disallow: '.$prefix.$path;
        }

        $extra = self::cleanExtra((string) setting('seo.robots_extra'));

        if ($extra !== '') {
            $lines[] = '';
            $lines[] = $extra;
        }

        if (setting('seo.sitemap')) {
            $lines[] = '';
            $lines[] = 'Sitemap: '.SiteAddress::url('sitemap.xml');
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Staff's own lines: plain text only, at most 100 lines.
     */
    public static function cleanExtra(string $text): string
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $lines = array_map(fn (string $line): string => trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $line)), $lines);

        return trim(implode("\n", array_slice($lines, 0, 100)));
    }
}
