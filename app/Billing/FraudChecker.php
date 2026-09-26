<?php

namespace App\Billing;

use App\Models\Client;
use App\Models\Order;
use Illuminate\Support\Str;

/**
 * Simple built-in fraud rules for new orders. A risky order is not refused: it waits for
 * staff to review it, and nothing is set up automatically until staff accept it.
 */
class FraudChecker
{
    /**
     * Throwaway email services often used for fraud.
     *
     * @var list<string>
     */
    public const DISPOSABLE_DOMAINS = [
        '10minutemail.com', '20minutemail.com', 'discard.email', 'dispostable.com', 'emailondeck.com', 'fakeinbox.com',
        'getairmail.com', 'getnada.com', 'guerrillamail.com', 'guerrillamail.net', 'guerrillamail.org', 'guerrillamailblock.com',
        'harakirimail.com', 'inboxbear.com', 'mail.tm', 'mailcatch.com', 'maildrop.cc', 'mailinator.com', 'mailinator.net',
        'mailnesia.com', 'mintemail.com', 'moakt.com', 'mohmal.com', 'mytemp.email', 'nada.email', 'sharklasers.com',
        'spam4.me', 'spamgourmet.com', 'temp-mail.io', 'temp-mail.org', 'tempail.com', 'tempmail.dev', 'tempmail.net',
        'tempmailo.com', 'tempr.email', 'throwawaymail.com', 'tmail.ws', 'tmpmail.net', 'trashmail.com', 'trashmail.de',
        'yopmail.com', 'yopmail.fr', 'yopmail.net',
    ];

    /**
     * Reasons the order looks risky. An empty list means it passed.
     *
     * @param  string|null  $ipCountry  Two-letter country of the visitor's IP, when a trusted proxy such as Cloudflare reports it.
     * @return list<string>
     */
    public function reasons(Client $client, ?string $ip, ?string $ipCountry = null): array
    {
        if (! setting('fraud.enabled')) {
            return [];
        }

        $reasons = [];

        if (setting('fraud.block_disposable_email') && in_array(Str::lower(Str::after($client->email, '@')), self::DISPOSABLE_DOMAINS, true)) {
            $reasons[] = __('The email address is from a throwaway email service.');
        }

        $limit = (int) setting('fraud.max_orders_per_ip');

        if ($ip !== null && $limit > 0) {
            $recent = Order::query()->where('ip_address', $ip)->where('created_at', '>=', now()->subDay())->count();

            if ($recent >= $limit) {
                $reasons[] = __(':count orders came from this IP address in the last 24 hours.', ['count' => $recent + 1]);
            }
        }

        $ipCountry = strtoupper((string) $ipCountry);

        if (setting('fraud.check_country') && preg_match('/^[A-Z]{2}$/', $ipCountry) && ! in_array($ipCountry, ['XX', 'T1'], true)
            && filled($client->country) && strtoupper($client->country) !== $ipCountry) {
            $reasons[] = __('The client lives in :country but ordered from an IP address in :ip.', ['country' => strtoupper($client->country), 'ip' => $ipCountry]);
        }

        return $reasons;
    }
}
