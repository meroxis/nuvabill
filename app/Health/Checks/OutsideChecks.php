<?php

namespace App\Health\Checks;

use App\Health\CheckGroup;
use App\Health\CheckResult;
use App\Support\Cloudflare;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * Opens a few private addresses on the site like a stranger would, to be sure the web server
 * blocks them, and looks at the security headers visitors get.
 */
class OutsideChecks extends CheckGroup
{
    /**
     * @var array<string, Response|string|null> Path => response, or the connection error.
     */
    private array $responses = [];

    public function key(): string
    {
        return 'outside';
    }

    public function section(): string
    {
        return self::SECURITY;
    }

    public function title(): string
    {
        return 'Your site from outside';
    }

    public function description(): string
    {
        return 'What a stranger can open on your site over the internet.';
    }

    public function icon(): string
    {
        return 'globe';
    }

    public function run(): array
    {
        $this->responses = [];
        $base = rtrim((string) config('app.url'), '/');
        $host = (string) parse_url($base, PHP_URL_HOST);

        $probes = [
            ['outside.env', 'The .env file cannot be opened', 5, '/.env', fn (string $body): bool => (bool) preg_match('/^(APP_KEY|DB_PASSWORD|APP_ENV)=/m', $body), 'It holds your database password and app key. Point your website folder at Nuvabill\'s "public" folder, or keep Nuvabill\'s own .htaccess in the Nuvabill folder, which blocks it.'],
            ['outside.git', 'The .git folder cannot be opened', 5, '/.git/HEAD', fn (string $body): bool => str_starts_with(ltrim($body), 'ref:'), 'Anyone could download your code and its history. Point your website folder at Nuvabill\'s "public" folder, or keep Nuvabill\'s own .htaccess in the Nuvabill folder, which blocks it.'],
            ['outside.logs', 'Log files cannot be opened', 5, '/storage/logs/laravel.log', fn (string $body): bool => (bool) preg_match('/^\[\d{4}-\d{2}-\d{2}/m', $body), 'Logs can show email addresses, errors and parts of requests. Point your website folder at Nuvabill\'s "public" folder, or keep Nuvabill\'s own .htaccess in the Nuvabill folder, which blocks it.'],
            ['outside.vendor', 'The vendor folder and composer files cannot be opened', 2, '/composer.json', fn (string $body): bool => str_contains($body, '"require"'), 'The list of libraries and their versions helps attackers pick a known weakness. Point your website folder at Nuvabill\'s "public" folder, or keep Nuvabill\'s own .htaccess in the Nuvabill folder, which blocks it.'],
            ['outside.database', 'The database file cannot be downloaded', 5, '/database/database.sqlite', fn (string $body): bool => str_starts_with($body, 'SQLite format 3'), 'An SQLite database holds every client, invoice and password hash. Point your website folder at Nuvabill\'s "public" folder, or keep Nuvabill\'s own .htaccess in the Nuvabill folder, which blocks it.'],
        ];

        if (! setting('health.outside_check')) {
            return array_map(fn (array $probe): CheckResult => $this->check($probe[0], $probe[1], $probe[2])->skipped('Opening the site from outside is switched off in the site health settings.'), $probes);
        }

        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true) || preg_match('/\.(test|local|localhost|invalid|example)$/', $host)) {
            return array_map(fn (array $probe): CheckResult => $this->check($probe[0], $probe[1], $probe[2])->skipped('The site address :url cannot be reached from the internet.', ['url' => $base]), $probes);
        }

        $results = [];

        foreach ($probes as [$id, $title, $weight, $path, $exposed, $advice]) {
            $results[] = $this->probe($id, $title, $weight, $base, $path, $exposed, $advice);
        }

        $results[] = $this->listing($base);
        $results[] = $this->headers($base);
        $results[] = $this->proxy();

        return $results;
    }

    /**
     * @param  callable(string): bool  $exposed
     * @param  string  $advice  One whole text, so it can be translated when shown.
     */
    private function probe(string $id, string $title, int $weight, string $base, string $path, callable $exposed, string $advice): CheckResult
    {
        $check = $this->check($id, $title, $weight);
        $response = $this->get($base.$path);

        if (is_string($response)) {
            return $check->skipped('Your site could not be reached: :error', ['error' => $response]);
        }

        $status = $response->status();

        if ($response->successful() && $exposed((string) substr($response->body(), 0, 65536))) {
            return $check->failed($weight >= 5, 'Anyone can open :path', ['path' => $path],
                advice: $advice,
                items: [['label' => $base.$path, 'value' => __('Opened (:status)', ['status' => $status]), 'mono' => true, 'status' => $weight >= 5 ? 'urgent' : 'warning']],
            );
        }

        return $status >= 400 ? $check->passed('Blocked (:status)', ['status' => $status]) : $check->passed('Not readable');
    }

    private function listing(string $base): CheckResult
    {
        $check = $this->check('outside.listing', 'Folder listings are off', 1);
        $response = $this->get($base.'/build/');

        if (is_string($response)) {
            return $check->skipped('Your site could not be reached: :error', ['error' => $response]);
        }

        if ($response->successful() && preg_match('/<title>\s*Index of\b/i', $response->body())) {
            return $check->warning('The web server lists the files of a folder',
                advice: 'Add "Options -Indexes" to the .htaccess file of your website folder, or ask your host to turn off directory listings.',
                items: [['label' => $base.'/build/', 'mono' => true, 'status' => 'warning']],
            );
        }

        return $check->passed();
    }

    private function headers(string $base): CheckResult
    {
        $check = $this->check('outside.headers', 'Security headers arrive in the browser');
        $response = $this->get($base.'/');

        if (is_string($response)) {
            return $check->skipped('Your site could not be reached: :error', ['error' => $response]);
        }

        $csp = strtolower($response->header('Content-Security-Policy'));
        $missing = array_keys(array_filter([
            'Content-Security-Policy' => $csp === '',
            'X-Frame-Options' => $response->header('X-Frame-Options') === '' && ! str_contains($csp, 'frame-ancestors'),
            'X-Content-Type-Options' => $response->header('X-Content-Type-Options') === '',
            'Strict-Transport-Security' => str_starts_with($base, 'https://') && $response->header('Strict-Transport-Security') === '',
        ]));

        if ($missing === []) {
            return $check->passed('CSP, HSTS, frame guard');
        }

        return $check->warning('Missing: :headers', ['headers' => implode(', ', $missing)],
            advice: 'Nuvabill sends these headers itself, so something between Nuvabill and the visitor removes them: a proxy, a cache or the web server. Check its settings.',
        );
    }

    /**
     * Behind Cloudflare, sign-in limits and logs need to see the visitor's own address.
     */
    private function proxy(): CheckResult
    {
        $check = $this->check('outside.proxy', 'Visitor addresses are read correctly behind a proxy');
        $home = $this->responses[rtrim((string) config('app.url'), '/').'/'] ?? null;

        if (! $home instanceof Response) {
            return $check->skipped('Your site could not be reached.');
        }

        $cloudflare = $home->header('CF-RAY') !== '' || strtolower($home->header('Server')) === 'cloudflare';

        if (! $cloudflare) {
            return $check->passed('No proxy found in front of the site');
        }

        $proxies = config('trustedproxy.proxies');

        if ($proxies === '*' || (is_array($proxies) && collect(Cloudflare::IP_RANGES)->every(fn (string $range): bool => IpUtils::checkIp(Str::before($range, '/'), $proxies)))) {
            return $check->passed('Behind Cloudflare, and its addresses are trusted');
        }

        if (filled($proxies)) {
            return $check->warning('NUVABILL_TRUSTED_PROXIES is set, but Cloudflare\'s addresses are not in it',
                advice: 'Nuvabill then sees Cloudflare\'s address instead of the visitor\'s, so sign-in limits count every visitor as one. Use NUVABILL_TRUSTED_PROXIES=cloudflare, or list Cloudflare\'s address ranges as well as your own proxy.',
            );
        }

        return $check->warning('Your site is behind Cloudflare, but Nuvabill sees Cloudflare\'s address instead of the visitor\'s',
            advice: 'Sign-in limits then count every visitor as one, and logs show the wrong addresses. Add NUVABILL_TRUSTED_PROXIES=cloudflare to the .env file.',
        );
    }

    private function get(string $url): Response|string
    {
        if (array_key_exists($url, $this->responses)) {
            return $this->responses[$url];
        }

        try {
            $response = Http::timeout(8)->connectTimeout(5)->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'Nuvabill-SiteHealth/'.config('nuvabill.version')])
                ->get($url);
        } catch (Throwable $exception) {
            $response = Str::limit($exception->getMessage(), 120);
        }

        return $this->responses[$url] = $response;
    }
}
