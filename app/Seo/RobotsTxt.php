<?php

namespace App\Seo;

use App\Models\SeoRedirect;

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

        // Old addresses in a private part that forward, such as /clientarea.php: search engines may
        // open them, so they see the redirect. The longer Allow line wins over the Disallow line.
        foreach (self::redirectedPrivatePaths() as $path) {
            $lines[] = 'Allow: '.$prefix.'/'.$path;
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

    /**
     * The robots.txt line written by staff that stops search engines from opening $path, if any.
     * Lines for other search engines (after a User-agent line without *) do not count, and a staff
     * Allow line at least as long wins, as it does for Google.
     */
    public static function blockedByOwnLine(string $path): ?string
    {
        $prefix = rtrim((string) parse_url(SiteAddress::root(), PHP_URL_PATH), '/');
        $address = $prefix.'/'.ltrim($path, '/');
        $applies = true;
        $agents = null;
        $blocked = null;
        $blockedLength = -1;
        $allowedLength = -1;

        foreach (preg_split('/\R/u', self::cleanExtra((string) setting('seo.robots_extra'))) ?: [] as $line) {
            $rule = trim(explode('#', $line, 2)[0]);

            if ($rule === '') {
                continue;
            }

            if (preg_match('/^user-agent\s*:\s*(.*)$/i', $rule, $match)) {
                // Lines right after each other make one group of search engines.
                $agents = $agents === null ? [] : $agents;
                $agents[] = trim($match[1]);
                $applies = in_array('*', $agents, true);

                continue;
            }

            $agents = null;

            if (! $applies || ! preg_match('/^(allow|disallow)\s*:\s*(\S*)/i', $rule, $match) || $match[2] === '' || ! self::matches($match[2], $address)) {
                continue;
            }

            $length = strlen($match[2]);

            if (strtolower($match[1]) === 'allow') {
                $allowedLength = max($allowedLength, $length);
            } elseif ($length > $blockedLength) {
                $blockedLength = $length;
                $blocked = $rule;
            }
        }

        return $blocked !== null && $blockedLength > $allowedLength ? $blocked : null;
    }

    /**
     * Whether a robots.txt path rule (with * and an ending $) covers the address.
     */
    private static function matches(string $rule, string $address): bool
    {
        $ends = str_ends_with($rule, '$');
        $pattern = str_replace('\*', '.*', preg_quote($ends ? substr($rule, 0, -1) : $rule, '#'));

        return (bool) preg_match('#^'.$pattern.($ends ? '$' : '').'#u', $address);
    }

    /**
     * Old addresses that forward and start with a private path, at most 100.
     *
     * @return list<string>
     */
    private static function redirectedPrivatePaths(): array
    {
        $paths = rescue(fn () => SeoRedirect::query()
            ->where(function ($query): void {
                foreach (self::PRIVATE_PATHS as $private) {
                    $query->orWhere('from_path', 'like', ltrim($private, '/').'%');
                }
            })
            ->orderBy('from_path')->limit(500)->pluck('from_path')->all(), [], report: false);

        $paths = array_filter($paths, function (string $path): bool {
            foreach (self::PRIVATE_PATHS as $private) {
                if (str_starts_with('/'.$path, $private)) {
                    // Robots.txt reads * and $ as patterns, so such addresses are left out.
                    return ! preg_match('/[\s*$#]/u', $path);
                }
            }

            return false;
        });

        $paths = array_values(array_unique($paths));
        sort($paths);

        return array_slice($paths, 0, 100);
    }
}
