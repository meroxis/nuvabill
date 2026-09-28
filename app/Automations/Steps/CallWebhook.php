<?php

namespace App\Automations\Steps;

use App\Automations\Context;
use App\Automations\StepFailed;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Send the facts of the run as JSON to a web address, for example Zapier, Make, n8n or your own
 * system. Only HTTPS addresses on the internet: never this server or the local network.
 */
class CallWebhook extends Step
{
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

        if (! self::isAllowed($url)) {
            throw new StepFailed(__('Only HTTPS addresses on the internet can be used.'));
        }

        try {
            $response = Http::timeout(10)->withoutRedirecting()->acceptJson()->post($url, $context->webhookData());
        } catch (Throwable $exception) {
            throw new StepFailed(__('The address could not be reached: :error', ['error' => mb_substr($exception->getMessage(), 0, 160)]));
        }

        if (! $response->successful()) {
            throw new StepFailed(__('The address answered with error :status.', ['status' => $response->status()]));
        }

        return __('Sent the details to :host', ['host' => (string) parse_url($url, PHP_URL_HOST)]);
    }

    /**
     * HTTPS, and a host that does not point to this computer or a private network.
     */
    public static function isAllowed(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (! str_starts_with(strtolower($url), 'https://') || $host === '' || in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true)) {
            return false;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if (app()->runningUnitTests() && $addresses === []) {
            // Tests fake the HTTP calls; made-up hosts do not resolve.
            return true;
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return $addresses !== [];
    }
}
