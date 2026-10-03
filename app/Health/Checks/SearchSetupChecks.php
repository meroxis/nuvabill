<?php

namespace App\Health\Checks;

use App\Health\CheckGroup;
use App\Health\CheckResult;
use App\Seo\SiteAddress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Whether search engines may find the store at all: robots.txt, the sitemap and the site address.
 */
class SearchSetupChecks extends CheckGroup
{
    /**
     * Addresses other than the one in .env that visitors opened the store at: host => when last seen.
     */
    public const OTHER_HOSTS_KEY = 'seo.other_hosts';

    /**
     * A few are kept, so one made-up address in a request cannot hide a real second address.
     */
    private const MAX_OTHER_HOSTS = 5;

    /**
     * "Billing.Example.com." and "billing.example.com" are the same address.
     */
    public static function normalHost(string $host): string
    {
        return rtrim(strtolower(trim($host)), '.');
    }

    /**
     * Remember that a visitor opened the store at $host, when it is not the address in .env. Each
     * address is written at most once a day; after 7 days without visits it is forgotten.
     */
    public static function noteAddress(string $host): void
    {
        $host = self::normalHost($host);
        $configured = self::normalHost((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($host === '' || $host === $configured || ! SiteAddress::isReal('https://'.$host)) {
            return;
        }

        $seen = self::otherHosts();

        if (isset($seen[$host]) && $seen[$host] > now()->subDay()->getTimestamp()) {
            return;
        }

        $seen[$host] = now()->getTimestamp();
        arsort($seen);
        Cache::put(self::OTHER_HOSTS_KEY, array_slice($seen, 0, self::MAX_OTHER_HOSTS, true), now()->addDays(7));
    }

    /**
     * @return array<string, int> Host => when it was last seen, from the last 7 days.
     */
    private static function otherHosts(): array
    {
        $since = now()->subDays(7)->getTimestamp();
        $seen = [];

        foreach ((array) Cache::get(self::OTHER_HOSTS_KEY, []) as $host => $at) {
            if (is_string($host) && is_int($at) && $at >= $since) {
                $seen[$host] = $at;
            }
        }

        return $seen;
    }

    public function key(): string
    {
        return 'search-setup';
    }

    public function section(): string
    {
        return self::SEO;
    }

    public function title(): string
    {
        return 'Sitemap and robots.txt';
    }

    public function description(): string
    {
        return 'Whether search engines may find your store, and where they are sent.';
    }

    public function icon(): string
    {
        return 'globe';
    }

    public function run(): array
    {
        return [
            $this->visible(),
            $this->robotsFile(),
            $this->sitemap(),
            $this->address(),
            $this->oneAddress(),
            $this->fromOutside(),
        ];
    }

    private function visible(): CheckResult
    {
        $check = $this->check('seo.visible', 'Search engines may show your store', 5);

        return setting('seo.visible')
            ? $check->passed()
            : $check->warning(
                'Search engines are asked to stay away from every page.',
                advice: 'That is right for a test site. On your real store, turn it on so people can find you on Google.',
                fix: $this->fix('seo.turn_on', 'Let search engines in', ['key' => 'visible']),
            );
    }

    private function robotsFile(): CheckResult
    {
        $check = $this->check('seo.robots_file', 'robots.txt is made by Nuvabill', 3);

        if (! is_file(public_path('robots.txt'))) {
            return $check->passed();
        }

        return $check->warning(
            'There is a robots.txt file in the public folder.',
            advice: 'The web server shows that file instead of the one Nuvabill makes, so your search engine settings and the sitemap line do not reach search engines. Moving it to quarantine keeps a copy.',
            fix: $this->fix('seo.robots_file', 'Move it to quarantine', confirm: 'The file moves to storage/app/quarantine. Nothing is deleted.'),
        );
    }

    private function sitemap(): CheckResult
    {
        $check = $this->check('seo.sitemap', 'Search engines get a list of your pages', 3);

        return setting('seo.sitemap')
            ? $check->passed('Your sitemap: :url', ['url' => SiteAddress::url('sitemap.xml')])
            : $check->warning(
                'There is no sitemap.xml.',
                advice: 'A sitemap helps search engines find every product, also new ones, sooner.',
                fix: $this->fix('seo.turn_on', 'Turn on the sitemap', ['key' => 'sitemap']),
            );
    }

    private function address(): CheckResult
    {
        $check = $this->check('seo.address', 'The site address is a real, secure address', 3);
        $url = rtrim((string) config('app.url'), '/');

        if (! SiteAddress::isReal($url)) {
            return $check->warning(
                'The site address in .env is :url.',
                ['url' => $url ?: '—'],
                'Search engines and emails get links to this address. Set APP_URL in .env to your store address, for example https://billing.example.com.',
            );
        }

        if (! str_starts_with($url, 'https://')) {
            return $check->warning(
                'The site address :url does not use HTTPS.',
                ['url' => $url],
                'Google ranks secure sites higher and browsers warn about pages without HTTPS. Turn on SSL and change APP_URL in .env to https://.',
            );
        }

        return $check->passed(':url', ['url' => $url]);
    }

    private function oneAddress(): CheckResult
    {
        $check = $this->check('seo.one_address', 'Visitors reach the store at one address', 2);
        $host = self::normalHost((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        // Before 0.6.12 only the first other address was kept, under seo.other_host.
        $legacy = Cache::get('seo.other_host');
        $seen = [...array_keys(self::otherHosts()), ...(is_string($legacy) ? [$legacy] : [])];

        // "billing.example.com." (with the dot) is the same address, not another one.
        $others = collect($seen)->filter(fn (mixed $other): bool => is_string($other))
            ->map(fn (string $other): string => self::normalHost($other))
            ->reject(fn (string $other): bool => $other === '' || $other === $host)
            ->unique()->values();

        if ($others->isEmpty()) {
            return $check->passed();
        }

        return $check->warning(
            'The store also opens at :other, not only at :host.',
            ['other' => $others->implode(', '), 'host' => $host],
            'Search engines are told to use :host, but it is better when the other address forwards there. In Cloudflare, add a redirect rule; in cPanel, use Domains → Redirects.',
        );
    }

    private function fromOutside(): CheckResult
    {
        $check = $this->check('seo.outside', 'Search engines can open robots.txt and the sitemap', 3);
        $base = rtrim((string) config('app.url'), '/');

        if (! setting('health.outside_check')) {
            return $check->skipped('Opening the site from outside is switched off in the site health settings.');
        }

        if (! SiteAddress::isReal($base)) {
            return $check->skipped('The site address :url cannot be reached from the internet.', ['url' => $base]);
        }

        if (! setting('seo.visible')) {
            return $check->skipped('Search engines are asked to stay away, so there is nothing for them to open.');
        }

        try {
            $robots = Http::timeout(10)->withoutRedirecting()->get($base.'/robots.txt');
            $sitemap = setting('seo.sitemap') ? Http::timeout(10)->withoutRedirecting()->get($base.'/sitemap.xml') : null;
        } catch (Throwable $exception) {
            return $check->skipped('The site could not be opened: :error', ['error' => mb_substr($exception->getMessage(), 0, 160)]);
        }

        $problems = [];

        if (! $robots->successful() || ! str_contains($robots->body(), 'Made by Nuvabill')) {
            $problems[] = ['label' => '/robots.txt', 'value' => $robots->successful() ? __('Another file is shown') : __('Error :status', ['status' => $robots->status()])];
        }

        if ($sitemap !== null && (! $sitemap->successful() || ! str_contains($sitemap->body(), '<urlset'))) {
            $problems[] = ['label' => '/sitemap.xml', 'value' => __('Error :status', ['status' => $sitemap->status()])];
        }

        if ($problems === []) {
            return $check->passed();
        }

        return $check->warning(
            'Search engines do not get what Nuvabill makes.',
            advice: 'A file in the public folder, the web server or a cache like Cloudflare answers instead. Remove the file, or purge the cache for these addresses.',
            items: $problems,
        );
    }
}
