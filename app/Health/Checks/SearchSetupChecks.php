<?php

namespace App\Health\Checks;

use App\Health\CheckGroup;
use App\Health\CheckResult;
use App\Health\PendingCheck;
use App\Models\SeoRedirect;
use App\Seo\SiteAddress;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
     * The security headers Nuvabill sends itself, besides the Content-Security-Policy.
     */
    private const SECURITY_HEADERS = ['X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy', 'Strict-Transport-Security', 'Cross-Origin-Opener-Policy'];

    /**
     * Headers whose value never holds a comma, so a comma means two copies joined into one line.
     */
    private const SINGLE_VALUE_HEADERS = ['x-content-type-options', 'x-frame-options', 'strict-transport-security', 'cross-origin-opener-policy'];

    /**
     * 30 days: a shorter cache time for fingerprinted design files is reported.
     */
    private const YEAR_CACHE_FLOOR = 2592000;

    /**
     * @var array<string, Response|string> Answers from outside in this run, or the connection error.
     */
    private array $responses = [];

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
        $this->responses = [];

        return [
            $this->visible(),
            $this->robotsFile(),
            $this->sitemap(),
            $this->address(),
            $this->oneAddress(),
            $this->fromOutside(),
            $this->headersOnce(),
            $this->assetCache(),
            $this->oldAddresses(),
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

        if (($cannot = $this->cannotOpen($check, $base)) !== null) {
            return $cannot;
        }

        if (! setting('seo.visible')) {
            return $check->skipped('Search engines are asked to stay away, so there is nothing for them to open.');
        }

        $robots = $this->get($base.'/robots.txt');
        $sitemap = setting('seo.sitemap') ? $this->get($base.'/sitemap.xml') : null;

        foreach ([$robots, $sitemap] as $response) {
            if (is_string($response)) {
                return $check->skipped('The site could not be opened: :error', ['error' => $response]);
            }
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

    /**
     * A security header that arrives more than once: the web server or a CDN adds it on top of
     * Nuvabill's own copy. The robots.txt answer is used, as Nuvabill makes it on every site.
     */
    private function headersOnce(): CheckResult
    {
        $check = $this->check('seo.headers_once', 'Security headers are sent once', 2);
        $base = rtrim((string) config('app.url'), '/');

        if (($cannot = $this->cannotOpen($check, $base)) !== null) {
            return $cannot;
        }

        $robots = $this->get($base.'/robots.txt');

        if (is_string($robots)) {
            return $check->skipped('The site could not be opened: :error', ['error' => $robots]);
        }

        $twice = [];

        foreach (self::SECURITY_HEADERS as $name) {
            $values = $this->headerValues($robots, $name);

            if (count($values) > 1) {
                $twice[] = ['label' => $name, 'value' => Str::limit(implode(' | ', $values), 200), 'mono' => true];
            }
        }

        if ($twice === []) {
            return $check->passed();
        }

        return $check->warning(
            'Your web server or CDN adds these headers too. Browsers then get two copies, sometimes with different values.',
            advice: 'Remove them from the web server or CDN, or add their names to NUVABILL_SERVER_HEADERS in .env.',
            items: $twice,
        );
    }

    /**
     * Design files have a fingerprint in their name, so browsers may keep them for a year. Most
     * often the web server sends no cache time for them, and Cloudflare then sets 4 hours.
     */
    private function assetCache(): CheckResult
    {
        $check = $this->check('seo.asset_cache', 'Browsers keep design files for a year', 2);
        $base = rtrim((string) config('app.url'), '/');

        if (($cannot = $this->cannotOpen($check, $base)) !== null) {
            return $cannot;
        }

        $file = self::themeStylesheet();

        if ($file === null) {
            return $check->skipped('The design files are not built yet.');
        }

        $response = $this->get($base.'/build/'.$file);

        if (is_string($response)) {
            return $check->skipped('The site could not be opened: :error', ['error' => $response]);
        }

        if (! $response->successful()) {
            return $check->skipped('The site could not be opened: :error', ['error' => __('Error :status', ['status' => $response->status()])]);
        }

        $cache = strtolower(implode(', ', $this->headerValues($response, 'Cache-Control')));
        $maxAge = preg_match('/(?:^|[\s,])max-age=(\d+)/', $cache, $match) === 1 ? (int) $match[1] : null;

        if (preg_match('/(?:^|[\s,])(no-store|no-cache)(?:$|[\s,])/', $cache) === 1) {
            $maxAge = 0;
        }

        if ($maxAge !== null && $maxAge >= self::YEAR_CACHE_FLOOR) {
            return $check->passed();
        }

        return $check->warning(
            $maxAge === null ? 'Browsers are not told how long to keep design files.' : 'Browsers keep design files for only :hours hours, not a year.',
            $maxAge === null ? [] : ['hours' => (string) round($maxAge / 3600, 1)],
            'In Nginx, add a location /build/assets/ block with add_header Cache-Control "public, max-age=31536000, immutable". In Cloudflare, set Browser Cache TTL to "Respect existing headers". See the installation guide.',
            [['label' => '/build/'.$file, 'value' => 'Cache-Control: '.($cache !== '' ? $cache : '—'), 'mono' => true]],
        );
    }

    /**
     * On Nginx, an address like /clientarea.php is answered by the web server itself ("404 Not
     * Found") unless it sends unknown .php addresses to index.php, so the redirect never runs.
     */
    private function oldAddresses(): CheckResult
    {
        $check = $this->check('seo.old_addresses', 'Old WHMCS addresses reach Nuvabill', 1);
        $base = rtrim((string) config('app.url'), '/');

        if (! setting('seo.whmcs_addresses') || SeoRedirect::target('clientarea.php') === null) {
            return $check->skipped('Old WHMCS addresses are not forwarded, so there is nothing to check.');
        }

        if (($cannot = $this->cannotOpen($check, $base)) !== null) {
            return $cannot;
        }

        $response = $this->get($base.'/clientarea.php');

        if (is_string($response)) {
            return $check->skipped('The site could not be opened: :error', ['error' => $response]);
        }

        if ($response->redirect()) {
            return $check->passed();
        }

        return $check->warning(
            'Your web server answers old .php addresses itself, so they cannot be forwarded.',
            advice: 'Send unknown .php addresses to index.php. See the installation guide.',
            items: [['label' => '/clientarea.php', 'value' => __('Error :status', ['status' => $response->status()]), 'mono' => true]],
        );
    }

    /**
     * The built stylesheet of the Nova theme, such as "assets/theme-Ab12Cd34.css", from the Vite
     * manifest, or null when the design files are not built.
     */
    private static function themeStylesheet(): ?string
    {
        $path = public_path('build/manifest.json');
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $file = is_array($manifest) ? ($manifest['themes/nova/assets/theme.css']['file'] ?? null) : null;

        return is_string($file) && preg_match('#^assets/[A-Za-z0-9._-]+\.css$#', $file) === 1 ? $file : null;
    }

    /**
     * Why a check that opens the site from outside cannot run, or null when it can.
     */
    private function cannotOpen(PendingCheck $check, string $base): ?CheckResult
    {
        if (! setting('health.outside_check')) {
            return $check->skipped('Opening the site from outside is switched off in the site health settings.');
        }

        if (! SiteAddress::isReal($base)) {
            return $check->skipped('The site address :url cannot be reached from the internet.', ['url' => $base]);
        }

        return null;
    }

    /**
     * Each copy of a header, also when a proxy joined two copies into one line, such as
     * "nosniff, nosniff". Only headers whose value never holds a comma are split.
     *
     * @return list<string>
     */
    private function headerValues(Response $response, string $name): array
    {
        $values = [];

        foreach ($response->headers() as $key => $lines) {
            if (strcasecmp((string) $key, $name) === 0) {
                array_push($values, ...array_map(fn (mixed $line): string => (string) $line, (array) $lines));
            }
        }

        if (in_array(strtolower($name), self::SINGLE_VALUE_HEADERS, true)) {
            $values = array_merge([], ...array_map(fn (string $value): array => explode(',', $value), $values));
        }

        return array_values(array_filter(array_map('trim', $values), fn (string $value): bool => $value !== ''));
    }

    /**
     * One answer per address for each run, so robots.txt is opened once.
     */
    private function get(string $url): Response|string
    {
        if (! array_key_exists($url, $this->responses)) {
            try {
                $this->responses[$url] = Http::timeout(10)->withoutRedirecting()->get($url);
            } catch (Throwable $exception) {
                $this->responses[$url] = mb_substr($exception->getMessage(), 0, 160);
            }
        }

        return $this->responses[$url];
    }
}
