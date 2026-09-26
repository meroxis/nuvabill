<?php

namespace Nuvabill\Extensions\DirectAdmin;

use App\Extensions\Servers\Module;
use App\Extensions\Servers\ModuleResult;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * DirectAdmin through its API (CMD_API_*), signed in as an admin or reseller with a login key or password.
 */
class DirectAdminModule extends Module
{
    public function productFields(): array
    {
        return [
            'package' => [
                'label' => 'DirectAdmin package',
                'type' => 'text',
                'required' => true,
                'help' => 'The exact user package name in DirectAdmin, for example "starter".',
            ],
            'ip' => [
                'label' => 'IP address',
                'type' => 'text',
                'help' => 'The shared IP for new accounts. Empty uses the server\'s IP address.',
            ],
        ];
    }

    public function serverHelp(): string
    {
        return 'Username: an admin or reseller. Create a login key in DirectAdmin → Login Keys and paste it in "API token" (or enter the password). Also fill in the server\'s IP address.';
    }

    public function defaultPort(): int
    {
        return 2222;
    }

    public function testConnection(Server $server): ModuleResult
    {
        $response = $this->call($server, 'CMD_API_PACKAGES_USER', method: 'get');

        if (! $response['ok']) {
            return ModuleResult::fail($response['reason']);
        }

        $packages = array_values(array_filter((array) ($response['data']['list'] ?? [])));

        return ModuleResult::ok($packages === []
            ? __('Connected to DirectAdmin. No user packages found yet.')
            : __('Connected to DirectAdmin. Packages: :list', ['list' => implode(', ', $packages)]));
    }

    public function create(Service $service): ModuleResult
    {
        if (blank($service->domain)) {
            return ModuleResult::fail(__('A DirectAdmin account needs a domain name.'));
        }

        $ip = (string) ($this->productSetting($service, 'ip') ?: $service->server?->ip_address);

        if ($ip === '') {
            return ModuleResult::fail(__('Set the server\'s IP address, or an IP on the product.'));
        }

        $username = $service->username ?: $this->makeUsername((string) $service->domain);
        $password = $service->password ?: $this->makePassword();

        $response = $this->call($service->server, 'CMD_API_ACCOUNT_USER', [
            'action' => 'create',
            'add' => 'Submit',
            'username' => $username,
            'email' => $service->client->email,
            'passwd' => $password,
            'passwd2' => $password,
            'domain' => $service->domain,
            'package' => (string) $this->productSetting($service, 'package', ''),
            'ip' => $ip,
            'notify' => 'no',
        ], timeout: 120);

        return $response['ok']
            ? ModuleResult::ok(__('Account :username created.', ['username' => $username]), ['username' => $username, 'password' => $password])
            : ModuleResult::fail($response['reason']);
    }

    public function suspend(Service $service, string $reason): ModuleResult
    {
        return $this->result($this->call($service->server, 'CMD_API_SELECT_USERS', [
            'location' => 'CMD_SELECT_USERS',
            'suspend' => 'Suspend',
            'select0' => $service->username,
        ]), __('Account suspended.'));
    }

    public function unsuspend(Service $service): ModuleResult
    {
        return $this->result($this->call($service->server, 'CMD_API_SELECT_USERS', [
            'location' => 'CMD_SELECT_USERS',
            'suspend' => 'Unsuspend',
            'select0' => $service->username,
        ]), __('Account unsuspended.'));
    }

    public function terminate(Service $service): ModuleResult
    {
        return $this->result($this->call($service->server, 'CMD_API_SELECT_USERS', [
            'confirmed' => 'Confirm',
            'delete' => 'yes',
            'select0' => $service->username,
        ]), __('Account removed.'));
    }

    public function changePackage(Service $service): ModuleResult
    {
        return $this->result($this->call($service->server, 'CMD_API_MODIFY_USER', [
            'action' => 'package',
            'user' => $service->username,
            'package' => (string) $this->productSetting($service, 'package', ''),
        ]), __('Package changed.'));
    }

    /**
     * DirectAdmin has no one-time login for users, so this opens the sign-in page.
     */
    public function loginUrl(Service $service): ?string
    {
        $server = $service->server;

        if ($server === null) {
            return null;
        }

        return ($server->use_ssl ? 'https' : 'http').'://'.$server->hostname.':'.($server->port ?: $this->defaultPort()).'/';
    }

    /**
     * DirectAdmin usernames: lowercase letters and digits, start with a letter, at most 10 characters.
     */
    public function makeUsername(string $domain): string
    {
        $base = preg_replace('/[^a-z0-9]/', '', Str::lower(Str::before($domain, '.')));
        $base = ltrim((string) $base, '0123456789');

        return substr($base === '' ? 'u' : $base, 0, 5).Str::lower(Str::random(3));
    }

    private function makePassword(): string
    {
        return Str::password(14, symbols: false).'a7';
    }

    /**
     * @param  array{ok: bool, reason: string, data: array<string, mixed>}  $response
     */
    private function result(array $response, string $message): ModuleResult
    {
        return $response['ok'] ? ModuleResult::ok($message) : ModuleResult::fail($response['reason']);
    }

    /**
     * DirectAdmin answers with a URL-encoded body: "error=0&text=...&details=...".
     *
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, reason: string, data: array<string, mixed>}
     */
    private function call(?Server $server, string $command, array $params = [], string $method = 'post', int $timeout = 30): array
    {
        if ($server === null) {
            return ['ok' => false, 'reason' => __('No server is assigned.'), 'data' => []];
        }

        $url = ($server->use_ssl ? 'https' : 'http').'://'.$server->hostname.':'.($server->port ?: $this->defaultPort()).'/'.$command;

        try {
            $request = Http::withBasicAuth((string) ($server->username ?: 'admin'), (string) ($server->api_token ?: $server->password))
                ->timeout($timeout)
                ->asForm();
            $response = $method === 'get' ? $request->get($url, $params) : $request->post($url, $params);
        } catch (ConnectionException $exception) {
            return ['ok' => false, 'reason' => __('Could not connect to :host: :error', ['host' => $server->hostname, 'error' => $exception->getMessage()]), 'data' => []];
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return ['ok' => false, 'reason' => __('DirectAdmin rejected the username or login key.'), 'data' => []];
        }

        $body = trim($response->body());

        if (! $response->successful() || str_starts_with($body, '<')) {
            return ['ok' => false, 'reason' => __('DirectAdmin returned HTTP :status. Check the port and that the user may use the API.', ['status' => $response->status()]), 'data' => []];
        }

        parse_str($body, $data);

        if ((string) ($data['error'] ?? '0') !== '0') {
            $reason = trim(implode(' ', array_filter([(string) ($data['text'] ?? ''), strip_tags((string) ($data['details'] ?? ''))])));

            return ['ok' => false, 'reason' => 'DirectAdmin: '.($reason !== '' ? $reason : __('unknown error')), 'data' => $data];
        }

        return ['ok' => true, 'reason' => (string) ($data['text'] ?? 'OK'), 'data' => $data];
    }
}
