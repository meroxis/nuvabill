<?php

namespace Nuvabill\Extensions\Plesk;

use App\Extensions\Servers\Module;
use App\Extensions\Servers\ModuleResult;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use SimpleXMLElement;

/**
 * Plesk Obsidian through the REST API v2. Each service is a Plesk customer with one subscription.
 * One-click login uses the XML API "create_session".
 */
class PleskModule extends Module
{
    public function productFields(): array
    {
        return [
            'plan' => [
                'label' => 'Service plan',
                'type' => 'text',
                'required' => true,
                'help' => 'The exact service plan name in Plesk → Service Plans, for example "Default Domain".',
            ],
            'ip' => [
                'label' => 'IPv4 address',
                'type' => 'text',
                'help' => 'The shared IP for new subscriptions. Empty uses the server\'s IP address.',
            ],
        ];
    }

    public function serverHelp(): string
    {
        return 'Create an API key on the Plesk server with "plesk bin secret_key -c -ip-address <this server\'s IP>" and paste it in "API token". Or enter the admin username and password. Also fill in the server\'s IP address.';
    }

    public function defaultPort(): int
    {
        return 8443;
    }

    public function testConnection(Server $server): ModuleResult
    {
        return $this->attempt(function () use ($server): ModuleResult {
            $info = $this->call($server, 'get', 'server');

            return ModuleResult::ok(__('Connected to Plesk :version.', ['version' => $info['panel_version'] ?? '']));
        });
    }

    public function create(Service $service): ModuleResult
    {
        if (blank($service->domain)) {
            return ModuleResult::fail(__('A Plesk subscription needs a domain name.'));
        }

        $ip = (string) ($this->productSetting($service, 'ip') ?: $service->server?->ip_address);

        if ($ip === '') {
            return ModuleResult::fail(__('Set the server\'s IP address, or an IP on the product.'));
        }

        return $this->attempt(function () use ($service, $ip): ModuleResult {
            $username = $service->username ?: $this->makeUsername((string) $service->domain);
            $password = $service->password ?: $this->makePassword();
            $client = $service->client;

            $customer = $this->call($service->server, 'post', 'clients', [
                'name' => $client->name,
                'company' => (string) $client->company_name,
                'login' => $username,
                'password' => $password,
                'email' => $client->email,
                'type' => 'customer',
            ]);

            try {
                $subscription = $this->call($service->server, 'post', 'domains', [
                    'name' => $service->domain,
                    'hosting_type' => 'virtual',
                    'hosting_settings' => ['ftp_login' => $username, 'ftp_password' => $password],
                    'owner_client' => ['id' => $customer['id']],
                    'ipv4' => [$ip],
                    'plan' => ['name' => (string) $this->productSetting($service, 'plan', '')],
                ], timeout: 120);
            } catch (RuntimeException $exception) {
                // Do not leave an empty customer behind, so the next try can use the same login.
                rescue(fn () => $this->call($service->server, 'delete', 'clients/'.$customer['id']), report: false);

                throw $exception;
            }

            return ModuleResult::ok(__('Subscription :domain created.', ['domain' => $service->domain]), [
                'username' => $username,
                'password' => $password,
                'module_data' => ['client_id' => (int) $customer['id'], 'domain_id' => (int) $subscription['id']],
            ]);
        });
    }

    public function suspend(Service $service, string $reason): ModuleResult
    {
        return $this->setStatus($service, 'suspended', __('Subscription suspended.'));
    }

    public function unsuspend(Service $service): ModuleResult
    {
        return $this->setStatus($service, 'active', __('Subscription unsuspended.'));
    }

    /**
     * Removes the customer Nuvabill made for this service. A service set up elsewhere (for example an
     * import) has no stored customer, and its customer may own other subscriptions, so only its own
     * subscription is removed.
     */
    public function terminate(Service $service): ModuleResult
    {
        return $this->attempt(function () use ($service): ModuleResult {
            $clientId = (int) $this->moduleValue($service, 'client_id', 0);

            if ($clientId > 0) {
                $this->call($service->server, 'delete', 'clients/'.$clientId);

                return ModuleResult::ok(__('Customer and subscription removed.'));
            }

            $this->call($service->server, 'delete', 'domains/'.$this->domainId($service));

            return ModuleResult::ok(__('Subscription :domain removed. Its Plesk customer was not created by Nuvabill, so it stays.', ['domain' => $service->domain]));
        });
    }

    public function changePackage(Service $service): ModuleResult
    {
        return $this->attempt(function () use ($service): ModuleResult {
            $response = $this->call($service->server, 'post', 'cli/subscription/call', [
                'params' => ['--switch-subscription', (string) $service->domain, '-service-plan', (string) $this->productSetting($service, 'plan', '')],
            ]);

            if ((int) ($response['code'] ?? 0) !== 0) {
                return ModuleResult::fail('Plesk: '.trim((string) ($response['stderr'] ?? $response['stdout'] ?? __('unknown error'))));
            }

            return ModuleResult::ok(__('Service plan changed.'));
        });
    }

    /**
     * A one-time Plesk session for the customer, opened in the client's browser.
     */
    public function loginUrl(Service $service): ?string
    {
        $server = $service->server;

        if ($server === null || blank($service->username)) {
            return null;
        }

        $packet = new SimpleXMLElement('<packet/>');
        $session = $packet->addChild('server')->addChild('create_session');
        $session->addChild('login', htmlspecialchars((string) $service->username, ENT_XML1));
        $data = $session->addChild('data');
        $data->addChild('user_ip', base64_encode((string) request()->ip()));
        $data->addChild('source_server', '');

        try {
            $response = $this->request($server, 20)
                ->withBody((string) $packet->asXML(), 'text/xml')
                ->post($this->baseUrl($server).'/enterprise/control/agent.php');
        } catch (ConnectionException) {
            return null;
        }

        $xml = @simplexml_load_string($response->body());
        $result = $xml?->server?->create_session?->result;

        if ($result === null || (string) $result->status !== 'ok' || (string) $result->id === '') {
            return null;
        }

        return $this->baseUrl($server).'/enterprise/rsession_init.php?PLESKSESSID='.rawurlencode((string) $result->id);
    }

    /**
     * Plesk logins: lowercase letters and digits, start with a letter.
     */
    public function makeUsername(string $domain): string
    {
        $base = preg_replace('/[^a-z0-9]/', '', Str::lower(Str::before($domain, '.')));
        $base = ltrim((string) $base, '0123456789');

        return substr($base === '' ? 'u' : $base, 0, 10).Str::lower(Str::random(4));
    }

    private function setStatus(Service $service, string $status, string $message): ModuleResult
    {
        return $this->attempt(function () use ($service, $status, $message): ModuleResult {
            $this->call($service->server, 'put', 'domains/'.$this->domainId($service).'/status', ['status' => $status]);

            return ModuleResult::ok($message);
        });
    }

    private function domainId(Service $service): int
    {
        return (int) ($this->moduleValue($service, 'domain_id') ?: $this->lookupDomainId($service));
    }

    /**
     * Find the subscription by its domain, for services set up outside Nuvabill (for example
     * imported ones). Only a row whose name is exactly this domain counts: Plesk may ignore the
     * filter and list every subscription, and acting on the wrong one would hit another customer.
     */
    private function lookupDomainId(Service $service): int
    {
        $domain = mb_strtolower(trim((string) $service->domain));

        if ($domain === '') {
            throw new RuntimeException(__('This service has no domain to find its Plesk subscription.'));
        }

        $found = $this->call($service->server, 'get', 'domains?name='.rawurlencode($domain));
        $rows = array_is_list($found) ? $found : [$found];

        $matches = array_values(array_filter($rows, fn (mixed $row): bool => is_array($row)
            && (mb_strtolower((string) ($row['name'] ?? '')) === $domain || mb_strtolower((string) ($row['ascii_name'] ?? '')) === $domain)));

        $id = count($matches) === 1 ? (int) ($matches[0]['id'] ?? 0) : 0;

        if ($id <= 0) {
            throw new RuntimeException(__('Plesk has no single subscription for :domain.', ['domain' => $domain]));
        }

        // Remember it, so the next action does not need to look it up again.
        $service->module_data = array_merge((array) $service->module_data, ['domain_id' => $id]);
        $service->save();

        return $id;
    }

    private function makePassword(): string
    {
        return Str::password(14, symbols: false).'!a7';
    }

    /**
     * @param  callable(): ModuleResult  $callback
     */
    private function attempt(callable $callback): ModuleResult
    {
        try {
            return $callback();
        } catch (RuntimeException $exception) {
            return ModuleResult::fail($exception->getMessage());
        }
    }

    private function baseUrl(Server $server): string
    {
        return ($server->use_ssl ? 'https' : 'http').'://'.$server->hostname.':'.($server->port ?: $this->defaultPort());
    }

    private function request(Server $server, int $timeout): PendingRequest
    {
        $request = Http::timeout($timeout);

        return filled($server->api_token)
            ? $request->withHeaders(['X-API-Key' => (string) $server->api_token, 'KEY' => (string) $server->api_token])
            : $request->withBasicAuth((string) ($server->username ?: 'admin'), (string) $server->password)
                ->withHeaders(['HTTP_AUTH_LOGIN' => (string) ($server->username ?: 'admin'), 'HTTP_AUTH_PASSWD' => (string) $server->password]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<int|string, mixed>
     */
    private function call(?Server $server, string $method, string $path, array $body = [], int $timeout = 30): array
    {
        if ($server === null) {
            throw new RuntimeException(__('No server is assigned.'));
        }

        try {
            $request = $this->request($server, $timeout)->acceptJson()->asJson();
            $url = $this->baseUrl($server).'/api/v2/'.$path;

            /** @var Response $response */
            $response = match ($method) {
                'get' => $request->get($url),
                'put' => $request->put($url, $body),
                'delete' => $request->delete($url),
                default => $request->post($url, $body),
            };
        } catch (ConnectionException $exception) {
            throw new RuntimeException(__('Could not connect to :host: :error', ['host' => $server->hostname, 'error' => $exception->getMessage()]));
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException(__('Plesk rejected the API key or password.'));
        }

        if (! $response->successful()) {
            $errors = collect((array) $response->json('errors', []))->flatten()->implode(' ');

            throw new RuntimeException('Plesk: '.trim(($response->json('message') ?? __('HTTP :status', ['status' => $response->status()])).' '.$errors));
        }

        return (array) $response->json();
    }
}
