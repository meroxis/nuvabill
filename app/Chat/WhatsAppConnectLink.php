<?php

namespace App\Chat;

use App\Marketplace\MarketplaceClient;
use App\Marketplace\SiteConnection;
use App\Models\MarketplaceSite;
use App\Support\Demo;

/**
 * The link that opens the WhatsApp connect page (WhatsAppConnectController) and the check that
 * page makes before it shows Meta's signup. The page hands the owner's WhatsApp access token to
 * the site named in the link, so it only works for a site the page knows:
 *
 * - the page's own site, whose admin area signs the link with a key made from the app key;
 * - on the marketplace store's page, a site connected to the marketplace. It signs the link with
 *   its marketplace key, and the address in the link must be the one it connected with;
 * - for people who run their own signup page: a site listed in META_WHATSAPP_QR_SITES;
 * - while META_WHATSAPP_QR_UNSIGNED is on: a connected site on an older Nuvabill, which sends
 *   no signature yet.
 */
class WhatsAppConnectLink
{
    /**
     * How long a signed link opens the page.
     */
    public const MINUTES = 60;

    /**
     * Extra time to finish the signup once the page is open.
     */
    public const FINISH_MINUTES = 30;

    private const PURPOSE = 'nuvabill-whatsapp-connect';

    public function __construct(
        private SiteConnection $connection,
        private MarketplaceClient $marketplace,
    ) {}

    /**
     * The connect page's address for this site, opened from Settings → Chat apps. Null when the
     * page would turn this site away: the marketplace store's page needs a site that is connected
     * to the marketplace under the address the admin area is open on.
     */
    public function url(string $state): ?string
    {
        $page = (string) config('nuvabill.whatsapp_connect.url');
        $origin = (string) self::originOf(url('/'));
        $values = ['origin' => $origin, 'state' => $state];

        if (self::host($page) === self::host($origin)) {
            $values += self::signed($origin, $state, 0, self::ownKey());
        } elseif (self::host($page) === self::host((string) config('nuvabill.marketplace.url'))) {
            $key = $this->connection->status() === SiteConnection::RECONNECT ? null : $this->connection->key();
            $site = (int) setting('marketplace.site_id');

            if ($key !== null && $site > 0 && $origin === 'https://'.$this->marketplace->site()) {
                $values += self::signed($origin, $state, $site, MarketplaceSite::hashKey($key));
            } elseif (! Demo::isEnabled()) {
                // The demo shows the button, but connecting is locked there.
                return null;
            }
        }

        return $page.'?'.http_build_query($values);
    }

    /**
     * Whether the connect page, open at $pageOrigin, may run Meta's signup for these values and
     * hand the token to their origin. Until the page is opened to everyone, only for its own site.
     *
     * @param  array<string, mixed>  $values  origin and state, and from a site that signs: site, time and signature.
     * @param  int  $extraMinutes  More time for a link the page was already opened with.
     */
    public function accepts(array $values, string $pageOrigin, int $extraMinutes = 0): bool
    {
        foreach ($values as $given) {
            if ($given !== null && ! is_scalar($given)) {
                return false;
            }
        }

        $value = fn (string $name): string => (string) ($values[$name] ?? '');
        $origin = self::originOf($value('origin'));
        $own = $origin !== null && $origin === self::originOf($pageOrigin);

        if ($origin === null || preg_match('/^[A-Za-z0-9]{32}$/', $value('state')) !== 1 || (! $own && ! config('nuvabill.whatsapp_connect.open'))) {
            return false;
        }

        // Plain http only on this computer, for testing.
        if (str_starts_with($origin, 'http://') && ! in_array(self::host($origin), ['localhost', '127.0.0.1'], true)) {
            return false;
        }

        if ($value('signature') === '') {
            return ! $own && $this->acceptsUnsigned($origin);
        }

        if (preg_match('/^\d{1,10}$/', $value('site')) !== 1 || preg_match('/^\d{9,11}$/', $value('time')) !== 1) {
            return false;
        }

        $site = (int) $value('site');
        $time = (int) $value('time');

        if ($time > now()->addMinutes(5)->getTimestamp() || $time < now()->subMinutes(self::MINUTES + $extraMinutes)->getTimestamp()) {
            return false;
        }

        foreach ($site === 0 ? ($own ? [self::ownKey()] : []) : $this->siteKeys($site, $origin) as $key) {
            if (hash_equals(self::signature($origin, $value('state'), $site, $time, $key), $value('signature'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * "https://Example.com/billing/" becomes "https://example.com", the form a browser uses for a
     * page's origin. Null when the text is not the plain address of a site.
     */
    public static function originOf(string $address): ?string
    {
        $parts = parse_url($address);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if (! in_array($scheme, ['https', 'http'], true) || preg_match('/^[a-z0-9.\-]+$|^\[[0-9a-f:.]+\]$/', $host) !== 1) {
            return null;
        }

        $port = isset($parts['port']) && $parts['port'] !== ($scheme === 'https' ? 443 : 80) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port;
    }

    /**
     * The keys a connected site may have signed with: its key, and for a day the one before it.
     * None when the link's address is not the address the site connected with.
     *
     * @return list<string>
     */
    private function siteKeys(int $id, string $origin): array
    {
        $site = MarketplaceSite::query()->find($id);

        if ($site === null || ! self::isTrusted($site) || $origin !== 'https://'.strtolower($site->host)) {
            return [];
        }

        $previous = $site->previous_key_hash !== null && $site->previous_key_expires_at?->isFuture() ? [(string) $site->previous_key_hash] : [];

        return [(string) $site->key_hash, ...$previous];
    }

    /**
     * A link without a signature: from a site the owner of this page listed, or, while that is
     * still allowed, from a connected site on an older Nuvabill.
     */
    private function acceptsUnsigned(string $origin): bool
    {
        $host = self::host($origin);

        if ($origin !== 'https://'.$host) {
            return false;
        }

        if (in_array($host, array_map('strtolower', (array) config('nuvabill.whatsapp_connect.sites')), true)) {
            return true;
        }

        return (bool) config('nuvabill.whatsapp_connect.unsigned')
            && MarketplaceSite::query()->usable()->where('host', $host)->get()->contains(fn (MarketplaceSite $site): bool => self::isTrusted($site));
    }

    /**
     * A connected site whose key works and that finished connecting. A site staff removed before
     * waits for staff again when it connects anew, and until they decide it is not trusted here.
     */
    private static function isTrusted(MarketplaceSite $site): bool
    {
        return $site->isUsable()
            && $site->ip !== ''
            && ! ($site->status === MarketplaceSite::STATUS_PENDING && $site->pending_reason === MarketplaceSite::REASON_REVOKED);
    }

    /**
     * @return array{site: int, time: int, signature: string}
     */
    private static function signed(string $origin, string $state, int $site, string $key): array
    {
        $time = now()->getTimestamp();

        return ['site' => $site, 'time' => $time, 'signature' => self::signature($origin, $state, $site, $time, $key)];
    }

    private static function signature(string $origin, string $state, int $site, int $time, string $key): string
    {
        return hash_hmac('sha256', implode("\n", [self::PURPOSE, $origin, $state, $site, $time]), $key);
    }

    /**
     * The key this site signs links to its own page with. Made from the app key, for this use only.
     */
    private static function ownKey(): string
    {
        return hash_hmac('sha256', self::PURPOSE, (string) config('app.key'));
    }

    private static function host(string $address): string
    {
        return strtolower((string) parse_url($address, PHP_URL_HOST));
    }
}
