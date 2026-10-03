<?php

namespace App\Automations\Steps;

use App\Automations\Context;
use App\Automations\StepFailed;
use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Send the facts of the run as JSON to a web address, for example Zapier, Make, n8n or your own
 * system. Only HTTPS addresses on the internet: never this server or the local network.
 */
class CallWebhook extends Step
{
    /**
     * Looks up the IPv4 addresses of a host name. Tests replace it, since made-up names do not resolve.
     *
     * @var (Closure(string): list<string>)|null
     */
    public static ?Closure $resolveUsing = null;

    public function key(): string
    {
        return 'webhook';
    }

    public function label(): string
    {
        return 'Send to a web address';
    }

    public function fields(): array
    {
        return [self::field('url', 'Web address', 'url', ['required' => true, 'max' => 500, 'help' => 'Nuvabill sends a POST request with JSON: the trigger, the invoice, service or ticket, and the client.'])];
    }

    public function summary(array $config): string
    {
        return __('Send to :host', ['host' => (string) parse_url((string) ($config['url'] ?? ''), PHP_URL_HOST)]);
    }

    public function preview(Context $context, array $config): string
    {
        return __('Would send the details of :subject to :host', ['subject' => $context->label(), 'host' => (string) parse_url((string) ($config['url'] ?? ''), PHP_URL_HOST)]);
    }

    public function run(Context $context, array $config): string
    {
        $url = (string) ($config['url'] ?? '');
        $address = self::allowedAddress($url);

        if ($address === null) {
            throw new StepFailed(__('Only HTTPS addresses on the internet can be used.'));
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
        // Connect to the address that was checked, over IPv4, so a second DNS answer (an IPv6
        // record, or a name that changes its answer) cannot lead to this server or the network.
        $options = ['force_ip_resolve' => 'v4'];

        if ($address !== '' && $address !== $host) {
            $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:{$port}:{$address}"]];
        }

        try {
            $response = Http::timeout(10)->withoutRedirecting()->withOptions($options)->acceptJson()->post($url, $context->webhookData());
        } catch (Throwable $exception) {
            // The reason goes to the error log only: on the run page it would tell which ports are
            // open. The full address is left out, because services such as Zapier, Slack and
            // Discord keep their secret token in it.
            Log::warning('Automation web address could not be reached.', ['host' => $host, 'error' => self::withoutAddresses($exception->getMessage())]);

            throw new StepFailed(__('The address could not be reached.'));
        }

        if (! $response->successful()) {
            throw new StepFailed(__('The address answered with error :status.', ['status' => $response->status()]));
        }

        return __('Sent the details to :host', ['host' => $host]);
    }

    /**
     * HTTPS, and a host that only points to public internet addresses.
     */
    public static function isAllowed(string $url): bool
    {
        return self::allowedAddress($url) !== null;
    }

    /**
     * The checked IPv4 address to connect to, or null when the address may not be used. In tests,
     * made-up host names do not resolve and their calls are faked: that gives an empty string.
     */
    private static function allowedAddress(string $url): ?string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (! str_starts_with(strtolower($url), 'https://') || $host === '' || in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true)) {
            return null;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);

        if (app()->runningUnitTests() && $addresses === []) {
            return '';
        }

        foreach ($addresses as $address) {
            // Also refuses shared and special ranges such as 100.64.0.0/10 (carrier networks, cloud metadata).
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE) === false) {
                return null;
            }
        }

        return $addresses[0] ?? null;
    }

    /**
     * An error message with every web address cut down to its host, so no path, query or
     * password from the address reaches the log.
     */
    private static function withoutAddresses(string $message): string
    {
        $message = (string) preg_replace_callback(
            '~([a-z][a-z0-9+.-]*://\S*?)([).,;:\'"]*)(?=\s|$)~i',
            fn (array $match): string => ((string) parse_url($match[1], PHP_URL_HOST)).$match[2],
            $message,
        );

        return Str::limit($message, 300);
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        return array_values(self::$resolveUsing !== null ? (self::$resolveUsing)($host) : (gethostbynamel($host) ?: []));
    }
}
