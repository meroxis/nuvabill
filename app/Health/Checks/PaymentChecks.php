<?php

namespace App\Health\Checks;

use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
use App\Health\CheckGroup;
use App\Health\CheckResult;
use App\Models\Server;

/**
 * Payment gateways, domain registrars and server connections: nothing left in test mode on a
 * live site, payment notices checked, and passwords never sent over plain HTTP.
 */
class PaymentChecks extends CheckGroup
{
    /**
     * The values of a "mode" setting that mean testing, across the built-in gateways and registrars.
     */
    private const TEST_MODES = ['sandbox', 'test', 'stage', 'staging'];

    public function __construct(private readonly ExtensionManager $extensions) {}

    public function key(): string
    {
        return 'payments';
    }

    public function section(): string
    {
        return self::SECURITY;
    }

    public function title(): string
    {
        return 'Payments and servers';
    }

    public function description(): string
    {
        return 'Payment gateways, domain registrars and the connections to your servers.';
    }

    public function icon(): string
    {
        return 'card';
    }

    public function run(): array
    {
        return [
            $this->testMode(ExtensionManifest::TYPE_GATEWAY, 'payments.test_mode', 'No payment method is in test mode', 'Clients can check out, but no real money is taken.', 'admin.settings.gateways.index'),
            $this->webhooks(),
            $this->serverHttps(),
            $this->testMode(ExtensionManifest::TYPE_REGISTRAR, 'registrars.test_mode', 'No domain registrar is in test mode', 'Domains that clients pay for are not really registered.', 'admin.settings.registrars.index'),
        ];
    }

    private function testMode(string $type, string $id, string $title, string $why, string $route): CheckResult
    {
        $check = $this->check($id, $title);
        $testing = [];

        foreach ($this->extensions->ofType($type) as $manifest) {
            if (! $this->extensions->isEnabled($manifest->slug)) {
                continue;
            }

            $settings = $this->extensions->settings($manifest->slug);

            if (in_array((string) ($settings['mode'] ?? ''), self::TEST_MODES, true) || str_starts_with((string) ($settings['secret_key'] ?? ''), 'sk_test_')) {
                $testing[] = $manifest->name;
            }
        }

        if ($testing === []) {
            return $check->passed();
        }

        return $check->warning(':names: test mode', ['names' => implode(', ', $testing)],
            advice: $why.' Switch to live mode when you are done testing.',
            items: array_map(fn (string $name): array => ['label' => $name, 'value' => __('Test mode'), 'status' => 'warning'], $testing),
            link: $this->link($route, 'Open the settings'),
        );
    }

    private function webhooks(): CheckResult
    {
        $check = $this->check('payments.webhooks', 'Payment notices from PayPal are verified', weight: 1);

        if (! $this->extensions->isEnabled('paypal')) {
            return $check->passed('PayPal is not switched on');
        }

        if (filled($this->extensions->settings('paypal')['webhook_id'] ?? '')) {
            return $check->passed();
        }

        return $check->warning('PayPal has no webhook ID',
            advice: 'Without it, a payment is only seen when the client comes back to your site. If they close the page, the invoice stays unpaid until staff check it. Add a webhook in PayPal and paste its ID.',
            link: $this->link('admin.settings.gateways.index', 'Open payment gateways'),
        );
    }

    private function serverHttps(): CheckResult
    {
        $check = $this->check('servers.https', 'Server connections use HTTPS', weight: 5);
        $servers = Server::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'hostname', 'use_ssl']);
        $plain = $servers->reject(fn (Server $server): bool => $server->use_ssl);

        if ($plain->isEmpty()) {
            return $check->passed(':count servers', ['count' => $servers->count()]);
        }

        return $check->urgent(':names: passwords and API tokens are sent without encryption', ['names' => $plain->pluck('name')->implode(', ')],
            advice: 'Anyone on the network between Nuvabill and the server can read them. Turn on "Connect with HTTPS" for these servers.',
            items: $plain->map(fn (Server $server): array => ['label' => $server->name, 'value' => $server->hostname, 'mono' => true, 'status' => 'urgent'])->values()->all(),
            link: $this->link('admin.servers.index', 'Open servers'),
        );
    }
}
