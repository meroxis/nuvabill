<?php

namespace App\Health\Checks;

use App\Health\CheckGroup;
use App\Health\CheckResult;
use Illuminate\Support\Carbon;

/**
 * Settings in .env and on the server that decide how safe the site is: debug mode, HTTPS,
 * cookies, the app key and the PHP version.
 */
class SettingsChecks extends CheckGroup
{
    /**
     * When each PHP version stops getting security fixes (php.net/supported-versions).
     */
    private const PHP_END_OF_LIFE = [
        '8.1' => '2025-12-31',
        '8.2' => '2026-12-31',
        '8.3' => '2027-12-31',
        '8.4' => '2028-12-31',
        '8.5' => '2029-12-31',
    ];

    public function key(): string
    {
        return 'settings';
    }

    public function section(): string
    {
        return self::SECURITY;
    }

    public function title(): string
    {
        return 'Site settings';
    }

    public function description(): string
    {
        return 'Debug mode, HTTPS, cookies, the app key and the PHP version.';
    }

    public function icon(): string
    {
        return 'settings';
    }

    public function run(): array
    {
        return [
            $this->debug(),
            $this->https(),
            $this->secureCookies(),
            $this->appKey(),
            $this->php(),
            $this->staffTimeout(),
        ];
    }

    private function debug(): CheckResult
    {
        $check = $this->check('settings.debug', 'Debug mode is off', weight: 5);

        if (! config('app.debug')) {
            return $check->passed();
        }

        // APP_ENV=local alone is not enough: a copy at a public address shows its error pages to anyone.
        if (app()->environment('local') && $this->isLocal((string) config('app.url'))) {
            return $check->passed('On, but this is a development copy (APP_ENV=local)');
        }

        return $check->urgent('Debug mode is on',
            advice: 'Error pages then show your settings, file paths and parts of the database to anyone who causes an error.',
            fix: $this->fix('settings.debug_off', 'Turn it off', confirm: 'APP_DEBUG=false is written to the .env file.'),
        );
    }

    private function https(): CheckResult
    {
        $check = $this->check('settings.https', 'The site address uses HTTPS', weight: 5);
        $url = (string) config('app.url');

        if ($this->isLocal($url)) {
            return $check->skipped('The site address :url is a local address.', ['url' => $url]);
        }

        if (str_starts_with($url, 'https://')) {
            return $check->passed();
        }

        return $check->urgent('The site address is :url', ['url' => $url],
            advice: 'Without HTTPS, passwords and payment pages cross the internet unencrypted. Get a free certificate (AutoSSL or Let\'s Encrypt), then change APP_URL in the .env file to start with https://.',
        );
    }

    private function secureCookies(): CheckResult
    {
        $check = $this->check('settings.secure_cookies', 'Sign-in cookies are only sent over HTTPS', weight: 1);
        $url = (string) config('app.url');

        if ($this->isLocal($url) || ! str_starts_with($url, 'https://')) {
            return $check->skipped('The site does not use HTTPS yet.');
        }

        if (config('session.secure') === true) {
            return $check->passed();
        }

        return $check->warning('Browsers may also send the sign-in cookie over plain HTTP',
            advice: 'Nuvabill already asks browsers to use HTTPS only, so this is a second lock on the same door.',
            fix: $this->fix('settings.secure_cookies', 'Turn it on', confirm: 'SESSION_SECURE_COOKIE=true is written to the .env file.'),
        );
    }

    private function appKey(): CheckResult
    {
        $check = $this->check('settings.app_key', 'The app key is set', weight: 5);

        if (filled(config('app.key'))) {
            return $check->passed();
        }

        return $check->urgent('APP_KEY is empty',
            advice: 'The app key encrypts passwords of servers and gateways and protects sign-ins. Restore the .env file from your backup: a new key cannot read what the old one encrypted.',
        );
    }

    private function php(): CheckResult
    {
        $check = $this->check('settings.php', 'PHP still gets security fixes');
        $branch = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        $end = self::PHP_END_OF_LIFE[$branch] ?? null;

        if ($end === null) {
            return version_compare($branch, '8.5', '>')
                ? $check->passed('PHP :version', ['version' => PHP_VERSION])
                : $check->urgent('PHP :version no longer gets security fixes', ['version' => PHP_VERSION], advice: 'Choose PHP 8.4 in your hosting panel.');
        }

        $until = Carbon::parse($end);

        if ($until->isPast()) {
            return $check->urgent('PHP :version stopped getting security fixes on :date', ['version' => PHP_VERSION, 'date' => $until->translatedFormat('d M Y')],
                advice: 'Choose a newer PHP version in your hosting panel, for example in "Select PHP Version" or "MultiPHP Manager" in cPanel.',
            );
        }

        if ($until->lt(now()->addDays(90))) {
            return $check->warning('PHP :version gets security fixes until :date', ['version' => PHP_VERSION, 'date' => $until->translatedFormat('d M Y')],
                advice: 'Plan the move to a newer PHP version before then.',
            );
        }

        return $check->passed('PHP :version, fixes until :date', ['version' => PHP_VERSION, 'date' => $until->translatedFormat('M Y')]);
    }

    private function staffTimeout(): CheckResult
    {
        $check = $this->check('settings.session_lifetime', 'Staff are signed out after a while without use', weight: 1);
        $minutes = (int) config('session.lifetime');

        if ($minutes <= 480) {
            return $check->passed(':hours hours', ['hours' => round($minutes / 60, 1)]);
        }

        return $check->warning('Sign-ins stay open for :hours hours without use', ['hours' => round($minutes / 60)],
            advice: 'A computer left signed in stays open to anyone who sits down at it. Set SESSION_LIFETIME=120 in the .env file.',
        );
    }

    private function isLocal(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true) || (bool) preg_match('/\.(test|local|localhost)$/', $host);
    }
}
