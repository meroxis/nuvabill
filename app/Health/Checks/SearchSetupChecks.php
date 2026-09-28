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
        $other = Cache::get('seo.other_host');
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($other) || $other === $host) {
            return $check->passed();
        }

        return $check->warning(
            'The store also opens at :other, not only at :host.',
            ['other' => $other, 'host' => $host],
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
