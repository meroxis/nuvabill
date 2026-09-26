<?php

namespace Nuvabill\Extensions\DirectAdmin;

use App\Extensions\Servers\Module;
use App\Extensions\Servers\ModuleResult;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * DirectAdmin through its CMD_API commands, signed in as an admin or reseller with a login key.
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
                'help' => 'The exact user package name in DirectAdmin → Manage User Packages, for example "starter".',
            ],
        ];
    }

    public function serverHelp(): string
    {
        return 'Username is your admin or reseller name, for example "admin". Create a login key in DirectAdmin → Login Keys and paste it in "API token". You can use the account password instead, but a login key is safer. Set "IP address" to the shared IP for new accounts.';
    }

    public function defaultPort(): int
    {
        return 2222;
    }

    public function testConnection(Server $server): ModuleResult
    {
        $response = $this->call($server, 'CMD_API_LOGIN_TEST');

        return $response['ok']
            ? ModuleResult::ok(__('Connected to DirectAdmin.'))
            : ModuleResult::fail($response['reason']);
    }

    public function create(Service $service): ModuleResult
    {
        if (blank($service->domain)) {
            return ModuleResult::fail(__('A DirectAdmin account needs a domain name.'));
        }

        $ip = $this->accountIp($service->server);

        if ($ip === null) {
            return ModuleResult::fail(__('Set the server’s IP address, or add an IP to the DirectAdmin account, so new accounts can be created.'));
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
        ], post: true, timeout: 120);

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
        ], post: true));
    }

    public function unsuspend(Service $service): ModuleResult
    {
        return $this->result($this->call($service->server, 'CMD_API_SELECT_USERS', [
            'location' => 'CMD_SELECT_USERS',
            'suspend' => 'Unsuspend',
            'select0' => $service->username,
        ], post: true));
    }

    public function terminate(Service $service): ModuleResult
    {
        return $this->result($this->call($service->server, 'CMD_API_SELECT_USERS', [
            'confirmed' => 'Confirm',
            'delete' => 'yes',
            'select0' => $service->username,
        ], post: true, timeout: 120));
    }

    public function changePackage(Service $service): ModuleResult
    {
        return $this->result($this->call($service->server, 'CMD_API_MODIFY_USER', [
            'action' => 'package',
            'user' => $service->username,
            'package' => (string) $this->productSetting($service, 'package', ''),
        ], post: true));
    }

    /**
     * A one-time login link from DirectAdmin's JSON API, created while logged in as the client ("admin|client").
     */
    public function loginUrl(Service $service): ?string
    {
        $server = $service->server;

        if ($server === null || blank($service->username)) {
            return null;
        }

        try {
            $response = $this->request($server, $this->adminUsername($server).'|'.$service->username)
                ->asJson()
                ->post($this->baseUrl($server).'/api/login/url', ['redirectURL' => '/']);
        } catch (ConnectionException) {
            return null;
        }

        $url = $response->successful() ? $response->json('url') : null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * DirectAdmin usernames: lowercase letters and digits, start with a letter, at most 10 characters by default.
     */
    public function makeUsername(string $domain): string
    {
        $base = preg_replace('/[^a-z0-9]/', '', Str::lower(Str::before($domain, '.')));
        $base = ltrim((string) $base, '0123456789');

        if ($base === '' || in_array($base, ['admin', 'root', 'mail', 'test'], true)) {
            $base = 'u'.$base;
        }

        return substr($base, 0, 7).Str::lower(Str::random(3));
    }

    private function makePassword(): string
    {
        return Str::password(16, symbols: false).'!9a';
    }

    /**
     * The server's IP address, or the first IP the admin or reseller owns in DirectAdmin.
     */
    private function accountIp(?Server $server): ?string
    {
        if ($server === null) {
            return null;
        }

        if (filled($server->ip_address)) {
            return $server->ip_address;
        }

        $response = $this->call($server, 'CMD_API_SHOW_RESELLER_IPS');
        $ips = $response['data']['list'] ?? [];

        return $response['ok'] && is_array($ips) && filled($ips[0] ?? null) ? (string) $ips[0] : null;
    }

    /**
     * @param  array{ok: bool, reason: string, data: array<string, mixed>}  $response
     */
    private function result(array $response): ModuleResult
    {
        return $response['ok'] ? ModuleResult::ok($response['reason']) : ModuleResult::fail($response['reason']);
    }

    /**
     * Call a CMD_API command. DirectAdmin answers with URL-encoded text such as "error=0&text=...&details=...".
     *
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, reason: string, data: array<string, mixed>}
     */
    private function call(?Server $server, string $command, array $params = [], bool $post = false, int $timeout = 30): array
    {
        if ($server === null) {
            return ['ok' => false, 'reason' => __('No server is assigned.'), 'data' => []];
        }

        $url = $this->baseUrl($server).'/'.$command;

        try {
            $request = $this->request($server, $this->adminUsername($server))->timeout($timeout);
            $response = $post ? $request->asForm()->post($url, $params) : $request->get($url, $params);
        } catch (ConnectionException $exception) {
            return ['ok' => false, 'reason' => __('Could not connect to :host: :error', ['host' => $server->hostname, 'error' => $exception->getMessage()]), 'data' => []];
        }

        if ($response->status() === 401 || $response->status() === 403 || $this->isLoginPage($response)) {
            return ['ok' => false, 'reason' => __('DirectAdmin rejected the username or login key.'), 'data' => []];
        }

        parse_str(trim($response->body()), $data);

        if (! $response->successful() || ! array_key_exists('error', $data) && ! array_key_exists('list', $data)) {
            return ['ok' => false, 'reason' => __('DirectAdmin returned HTTP :status.', ['status' => $response->status()]), 'data' => []];
        }

        $ok = (string) ($data['error'] ?? '0') === '0';
        $reason = trim(implode(': ', array_filter([
            $this->plainText($data['text'] ?? ''),
            $ok ? '' : $this->plainText($data['details'] ?? ''),
        ])));

        return [
            'ok' => $ok,
            'reason' => $reason !== '' ? $reason : ($ok ? 'OK' : __('DirectAdmin reported an error.')),
            'data' => $data,
        ];
    }

    private function request(Server $server, string $username): PendingRequest
    {
        return Http::withBasicAuth($username, (string) ($server->api_token ?: $server->password));
    }

    private function baseUrl(Server $server): string
    {
        $scheme = $server->use_ssl ? 'https' : 'http';
        $port = $server->port ?: $this->defaultPort();

        return "{$scheme}://{$server->hostname}:{$port}";
    }

    private function adminUsername(Server $server): string
    {
        return $server->username ?: 'admin';
    }

    /**
     * Older DirectAdmin versions answer a bad login with the HTML login page and HTTP 200.
     */
    private function isLoginPage(Response $response): bool
    {
        return str_contains(Str::lower((string) $response->header('Content-Type')), 'text/html')
            && str_contains(Str::lower($response->body()), '<form');
    }

    private function plainText(mixed $value): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $value))) ?? '');
    }
}
