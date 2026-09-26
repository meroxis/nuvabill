<?php

namespace Nuvabill\Extensions\Cpanel;

use App\Extensions\Servers\Module;
use App\Extensions\Servers\ModuleResult;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * cPanel & WHM through WHM API 1, signed in with a WHM API token.
 */
class CpanelModule extends Module
{
    public function productFields(): array
    {
        return [
            'package' => [
                'label' => 'WHM package',
                'type' => 'text',
                'required' => true,
                'help' => 'The exact package name in WHM → Packages, for example "starter".',
            ],
        ];
    }

    public function serverHelp(): string
    {
        return 'Username is usually "root" (or a reseller). Create an API token in WHM → Development → Manage API Tokens and paste it in "API token".';
    }

    public function defaultPort(): int
    {
        return 2087;
    }

    public function testConnection(Server $server): ModuleResult
    {
        $response = $this->call($server, 'version');

        return $response['ok']
            ? ModuleResult::ok(__('Connected to WHM :version.', ['version' => $response['data']['version'] ?? '']))
            : ModuleResult::fail($response['reason']);
    }

    public function create(Service $service): ModuleResult
    {
        if (blank($service->domain)) {
            return ModuleResult::fail(__('A cPanel account needs a domain name.'));
        }

        $username = $service->username ?: $this->makeUsername((string) $service->domain);
        $password = $service->password ?: $this->makePassword();

        $response = $this->call($service->server, 'createacct', [
            'username' => $username,
            'domain' => $service->domain,
            'plan' => (string) $this->productSetting($service, 'package', ''),
            'contactemail' => $service->client->email,
            'password' => $password,
        ], timeout: 120);

        return $response['ok']
            ? ModuleResult::ok(__('Account :username created.', ['username' => $username]), ['username' => $username, 'password' => $password])
            : ModuleResult::fail($response['reason']);
    }

    public function suspend(Service $service, string $reason): ModuleResult
    {
        return $this->result($this->call($service->server, 'suspendacct', ['user' => $service->username, 'reason' => $reason]));
    }

    public function unsuspend(Service $service): ModuleResult
    {
        return $this->result($this->call($service->server, 'unsuspendacct', ['user' => $service->username]));
    }

    public function terminate(Service $service): ModuleResult
    {
        return $this->result($this->call($service->server, 'removeacct', ['username' => $service->username]));
    }

    public function changePackage(Service $service): ModuleResult
    {
        return $this->result($this->call($service->server, 'changepackage', [
            'user' => $service->username,
            'pkg' => (string) $this->productSetting($service, 'package', ''),
        ]));
    }

    public function loginUrl(Service $service): ?string
    {
        if ($service->server === null || blank($service->username)) {
            return null;
        }

        $response = $this->call($service->server, 'create_user_session', ['user' => $service->username, 'service' => 'cpaneld']);

        return $response['ok'] ? ($response['data']['url'] ?? null) : null;
    }

    /**
     * cPanel usernames: lowercase letters and digits, start with a letter, at most 16 characters, never "test...".
     */
    public function makeUsername(string $domain): string
    {
        $base = preg_replace('/[^a-z0-9]/', '', Str::lower(Str::before($domain, '.')));
        $base = ltrim((string) $base, '0123456789');

        if ($base === '' || str_starts_with($base, 'test')) {
            $base = 'u'.$base;
        }

        return substr($base, 0, 8).Str::lower(Str::random(3));
    }

    private function makePassword(): string
    {
        return Str::password(16, symbols: false).'!9a';
    }

    /**
     * @param  array{ok: bool, reason: string, data: array<string, mixed>}  $response
     */
    private function result(array $response): ModuleResult
    {
        return $response['ok'] ? ModuleResult::ok($response['reason']) : ModuleResult::fail($response['reason']);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, reason: string, data: array<string, mixed>}
     */
    private function call(?Server $server, string $function, array $params = [], int $timeout = 30): array
    {
        if ($server === null) {
            return ['ok' => false, 'reason' => __('No server is assigned.'), 'data' => []];
        }

        $scheme = $server->use_ssl ? 'https' : 'http';
        $port = $server->port ?: $this->defaultPort();
        $url = "{$scheme}://{$server->hostname}:{$port}/json-api/{$function}";

        try {
            $response = Http::withHeaders(['Authorization' => 'whm '.($server->username ?: 'root').':'.$server->api_token])
                ->timeout($timeout)
                ->acceptJson()
                ->get($url, ['api.version' => 1] + $params);
        } catch (ConnectionException $exception) {
            return ['ok' => false, 'reason' => __('Could not connect to :host: :error', ['host' => $server->hostname, 'error' => $exception->getMessage()]), 'data' => []];
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return ['ok' => false, 'reason' => __('WHM rejected the username or API token.'), 'data' => []];
        }

        $ok = (int) $response->json('metadata.result', 0) === 1;

        return [
            'ok' => $ok,
            'reason' => (string) $response->json('metadata.reason', $ok ? 'OK' : __('WHM returned HTTP :status.', ['status' => $response->status()])),
            'data' => (array) $response->json('data', []),
        ];
    }
}
