<?php

namespace Nuvabill\Extensions\Proxmox;

use App\Contracts\HasClientPanel;
use App\Enums\ServiceStatus;
use App\Extensions\Servers\Module;
use App\Extensions\Servers\ModuleResult;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Proxmox VE through its API with an API token. New servers are full clones of a cloud-init
 * template; cloud-init sets the root password and network on first boot.
 */
class ProxmoxModule extends Module implements HasClientPanel
{
    /**
     * How long to wait for a Proxmox task (stop, start, delete), in seconds.
     */
    private const TASK_TIMEOUT = 600;

    /**
     * create() runs in the setup job, which is stopped after 900 seconds. So all its tasks
     * together (clone, resize, start) get this long, and removing a half-made VM gets CLEANUP_TIMEOUT.
     * Both end in time for the error to be logged and the VM to be removed.
     */
    private const CREATE_TIMEOUT = 660;

    private const CLEANUP_TIMEOUT = 90;

    public function productFields(): array
    {
        return [
            'node' => ['label' => 'Node', 'type' => 'text', 'required' => true, 'help' => 'The Proxmox node name, for example "pve1".'],
            'template' => ['label' => 'Template VM ID', 'type' => 'text', 'required' => true, 'help' => 'A KVM template with cloud-init, for example 9000.'],
            'storage' => ['label' => 'Storage', 'type' => 'text', 'help' => 'Where to put the new disk, for example "local-lvm". Empty uses the template\'s storage.'],
            'cores' => ['label' => 'CPU cores', 'type' => 'text', 'required' => true],
            'memory' => ['label' => 'RAM (MB)', 'type' => 'text', 'required' => true],
            'disk' => ['label' => 'Disk size (GB)', 'type' => 'text', 'help' => 'The disk grows to this size. Empty keeps the template size.'],
            'disk_device' => ['label' => 'Disk device', 'type' => 'text', 'help' => 'Default "scsi0".'],
            'ip_config' => ['label' => 'Network (ipconfig0)', 'type' => 'text', 'help' => 'Default "ip=dhcp". Every VPS of this product gets this setting, so a fixed IP such as "ip=203.0.113.10/24,gw=203.0.113.1" only works for one VPS: set the product stock to 1.'],
        ];
    }

    public function serverHelp(): string
    {
        return 'Create an API token in Proxmox → Datacenter → Permissions → API Tokens. Put the token ID in "Username" (for example root@pam!nuvabill) and the secret in "API token". Port 8006 needs a valid SSL certificate (Proxmox can get one with ACME).';
    }

    public function defaultPort(): int
    {
        return 8006;
    }

    public function hasLoginLink(): bool
    {
        return false;
    }

    public function testConnection(Server $server): ModuleResult
    {
        return $this->attempt(function () use ($server): ModuleResult {
            $version = (array) $this->call($server, 'get', 'version');

            return ModuleResult::ok(__('Connected to Proxmox VE :version.', ['version' => $version['version'] ?? '']));
        });
    }

    public function create(Service $service): ModuleResult
    {
        return $this->attempt(function () use ($service): ModuleResult {
            $server = $service->server;
            $node = $this->node($service);
            $template = (int) $this->productSetting($service, 'template');

            if ($template === 0) {
                return ModuleResult::fail(__('Set the template VM ID on the product first.'));
            }

            $ipConfig = (string) ($this->productSetting($service, 'ip_config') ?: 'ip=dhcp');

            if ($this->fixedIpInUse($service, $ipConfig)) {
                return ModuleResult::fail(__('This product has a fixed IP that another service already uses. Give each VPS its own IP.'));
            }

            $deadline = now()->addSeconds(self::CREATE_TIMEOUT);
            $vmId = (int) $this->call($server, 'get', 'cluster/nextid');
            $password = $this->makePassword();

            $clone = $this->call($server, 'post', "nodes/{$node}/qemu/{$template}/clone", array_filter([
                'newid' => $vmId,
                'name' => $this->vmName($service),
                'full' => 1,
                'storage' => $this->productSetting($service, 'storage') ?: null,
                'description' => 'Nuvabill service #'.$service->id,
            ]));

            try {
                $this->waitFor($server, $node, $clone, $deadline);
            } catch (RuntimeException $exception) {
                // A clone that fails or is stopped removes its half-copied VM itself.
                $this->stopTask($server, $node, $clone);

                throw new RuntimeException($exception->getMessage().' '.__('Also check that VM :id was removed from the node.', ['id' => $vmId]));
            }

            // From here the VM exists. If a later step fails, remove it again: Nuvabill does not
            // store its ID, so it would be left on the node, and the next try clones a new one.
            try {
                $this->call($server, 'put', "nodes/{$node}/qemu/{$vmId}/config", [
                    'cores' => max(1, (int) $this->productSetting($service, 'cores', 1)),
                    'memory' => max(256, (int) $this->productSetting($service, 'memory', 1024)),
                    'ciuser' => 'root',
                    'cipassword' => $password,
                    'ipconfig0' => $ipConfig,
                    'onboot' => 1,
                ]);

                $this->resizeDisk($service, $node, $vmId, $deadline);
                $this->waitFor($server, $node, $this->call($server, 'post', "nodes/{$node}/qemu/{$vmId}/status/start"), $deadline);
            } catch (RuntimeException $exception) {
                if (! $this->removeVm($server, $node, $vmId)) {
                    throw new RuntimeException($exception->getMessage().' '.__('Also check that VM :id was removed from the node.', ['id' => $vmId]));
                }

                throw $exception;
            }

            return ModuleResult::ok(__('Virtual server :id created.', ['id' => $vmId]), [
                'username' => 'root',
                'password' => $password,
                'module_data' => ['vmid' => $vmId, 'node' => $node],
            ]);
        });
    }

    /**
     * Stops the VM and waits until it is off. Start on boot is turned off first, so a node
     * restart does not bring a suspended VM back. A stop that fails (for example while a backup
     * holds a lock) fails the suspension, so the service stays active and is tried again.
     */
    public function suspend(Service $service, string $reason): ModuleResult
    {
        return $this->attempt(function () use ($service): ModuleResult {
            [$node, $vmId] = $this->vm($service);
            $server = $service->server;

            $this->call($server, 'put', "nodes/{$node}/qemu/{$vmId}/config", ['onboot' => 0]);

            try {
                if (($this->status($service)['status'] ?? '') !== 'stopped') {
                    $this->waitFor($server, $node, $this->call($server, 'post', "nodes/{$node}/qemu/{$vmId}/status/stop"));
                }
            } catch (RuntimeException $exception) {
                // The service stays active, so it should start on boot again.
                rescue(fn () => $this->call($server, 'put', "nodes/{$node}/qemu/{$vmId}/config", ['onboot' => 1]), report: false);

                throw $exception;
            }

            return ModuleResult::ok(__('Virtual server stopped.'));
        });
    }

    /**
     * Turns start on boot back on and starts the VM. When the start fails, the service stays
     * suspended, so start on boot is turned off again.
     */
    public function unsuspend(Service $service): ModuleResult
    {
        return $this->attempt(function () use ($service): ModuleResult {
            [$node, $vmId] = $this->vm($service);
            $server = $service->server;

            $this->call($server, 'put', "nodes/{$node}/qemu/{$vmId}/config", ['onboot' => 1]);

            try {
                if (($this->status($service)['status'] ?? '') !== 'running') {
                    $this->waitFor($server, $node, $this->call($server, 'post', "nodes/{$node}/qemu/{$vmId}/status/start"));
                }
            } catch (RuntimeException $exception) {
                rescue(fn () => $this->call($server, 'put', "nodes/{$node}/qemu/{$vmId}/config", ['onboot' => 0]), report: false);

                throw $exception;
            }

            return ModuleResult::ok(__('Virtual server started.'));
        });
    }

    public function terminate(Service $service): ModuleResult
    {
        return $this->attempt(function () use ($service): ModuleResult {
            [$node, $vmId] = $this->vm($service);
            $server = $service->server;

            if (($this->status($service)['status'] ?? '') === 'running') {
                $this->waitFor($server, $node, $this->call($server, 'post', "nodes/{$node}/qemu/{$vmId}/status/stop"));
            }

            $this->waitFor($server, $node, $this->call($server, 'delete', "nodes/{$node}/qemu/{$vmId}", ['purge' => 1, 'destroy-unreferenced-disks' => 1]));

            return ModuleResult::ok(__('Virtual server :id deleted.', ['id' => $vmId]));
        });
    }

    public function changePackage(Service $service): ModuleResult
    {
        return $this->attempt(function () use ($service): ModuleResult {
            [$node, $vmId] = $this->vm($service);

            $this->call($service->server, 'put', "nodes/{$node}/qemu/{$vmId}/config", [
                'cores' => max(1, (int) $this->productSetting($service, 'cores', 1)),
                'memory' => max(256, (int) $this->productSetting($service, 'memory', 1024)),
            ]);

            $this->resizeDisk($service, $node, $vmId);

            return ModuleResult::ok(__('New plan saved. CPU and memory change after the next restart.'));
        });
    }

    public function clientPanelView(): string
    {
        return 'theme::client.services.vps-panel';
    }

    public function clientActions(): array
    {
        return [
            'start' => __('Start'),
            'stop' => __('Shut down'),
            'restart' => __('Restart'),
            'poweroff' => __('Power off'),
            'password' => __('Change root password'),
        ];
    }

    public function clientPanel(Service $service): array
    {
        $status = $this->status($service);
        [$node, $vmId] = $this->vm($service);
        $config = (array) $this->call($service->server, 'get', "nodes/{$node}/qemu/{$vmId}/config");

        return [
            'hostname' => $status['name'] ?? $config['name'] ?? $this->vmName($service),
            'os' => null,
            'state' => match (true) {
                ($status['qmpstatus'] ?? '') === 'paused' => 'suspended',
                ($status['status'] ?? '') === 'running' => 'running',
                ($status['status'] ?? '') === 'stopped' => 'stopped',
                default => 'unknown',
            },
            'cpu' => isset($status['cpu']) ? round((float) $status['cpu'] * 100, 1) : null,
            'ram' => isset($status['maxmem']) ? ['used' => round((float) ($status['mem'] ?? 0) / 1048576), 'total' => round((float) $status['maxmem'] / 1048576), 'unit' => 'MB'] : null,
            'ips' => $this->ipAddresses($service, $config, ($status['status'] ?? '') === 'running'),
        ];
    }

    public function clientAction(Service $service, string $action, array $input): ModuleResult
    {
        return match ($action) {
            'start' => $this->power($service, 'start', __('The server is starting.')),
            'stop' => $this->power($service, 'shutdown', __('The server is shutting down.')),
            'restart' => $this->power($service, 'reboot', __('The server is restarting.')),
            'poweroff' => $this->power($service, 'stop', __('The server is powered off.')),
            'password' => $this->changePassword($service, (string) ($input['password'] ?? '')),
            default => ModuleResult::fail(__('This action is not available.')),
        };
    }

    private function changePassword(Service $service, string $password): ModuleResult
    {
        if (strlen($password) < 10 || ! preg_match('/[a-z]/i', $password) || ! preg_match('/\d/', $password)) {
            return ModuleResult::fail(__('Use at least 10 characters with letters and numbers.'));
        }

        return $this->attempt(function () use ($service, $password): ModuleResult {
            [$node, $vmId] = $this->vm($service);

            $this->call($service->server, 'put', "nodes/{$node}/qemu/{$vmId}/config", ['cipassword' => $password]);
            $service->update(['password' => $password]);

            return ModuleResult::ok(__('Root password saved. It takes effect after you restart the server.'));
        });
    }

    /**
     * Start, stop, shutdown or reboot. Proxmox runs these as tasks, so this only starts them.
     */
    private function power(Service $service, string $command, string $message): ModuleResult
    {
        return $this->attempt(function () use ($service, $command, $message): ModuleResult {
            [$node, $vmId] = $this->vm($service);

            $this->call($service->server, 'post', "nodes/{$node}/qemu/{$vmId}/status/{$command}");

            return ModuleResult::ok($message);
        });
    }

    private function resizeDisk(Service $service, string $node, int $vmId, ?CarbonInterface $deadline = null): void
    {
        $size = (int) $this->productSetting($service, 'disk', 0);

        if ($size <= 0) {
            return;
        }

        $device = (string) ($this->productSetting($service, 'disk_device') ?: 'scsi0');
        $config = (array) $this->call($service->server, 'get', "nodes/{$node}/qemu/{$vmId}/config");

        if (preg_match('/size=(\d+(?:\.\d+)?)([KMGT])/', (string) ($config[$device] ?? ''), $match)) {
            $currentGb = (float) $match[1] * ['K' => 1 / 1048576, 'M' => 1 / 1024, 'G' => 1, 'T' => 1024][$match[2]];

            if ($currentGb >= $size) {
                return;
            }
        }

        $this->waitFor($service->server, $node, $this->call($service->server, 'put', "nodes/{$node}/qemu/{$vmId}/resize", ['disk' => $device, 'size' => $size.'G']), $deadline);
    }

    /**
     * Whether the product's fixed IP (not DHCP) is already used by a live VPS of a Proxmox product.
     * Two VMs with one IP knock each other offline.
     */
    private function fixedIpInUse(Service $service, string $ipConfig): bool
    {
        if (! preg_match('/(?:^|,)ip=([0-9.]+)\//', $ipConfig, $match)) {
            return false;
        }

        $sameIp = '/(?:^|,)ip='.preg_quote($match[1], '/').'\//';
        $productIds = Product::query()->where('server_module', $this->slug())->get(['id', 'module_config'])
            ->filter(fn (Product $product): bool => preg_match($sameIp, (string) ($product->module_config['ip_config'] ?? '')) === 1)
            ->modelKeys();

        return Service::query()
            ->whereIn('product_id', $productIds)
            ->whereKeyNot($service->id)
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])
            ->whereNotNull('module_data->vmid')
            ->exists();
    }

    /**
     * Stop and delete a VM that create() could not finish. False when that did not work,
     * so staff can be told to check the node. The create error stays the one shown.
     */
    private function removeVm(?Server $server, string $node, int $vmId): bool
    {
        return rescue(function () use ($server, $node, $vmId): bool {
            $deadline = now()->addSeconds(self::CLEANUP_TIMEOUT);
            $status = (array) $this->call($server, 'get', "nodes/{$node}/qemu/{$vmId}/status/current");

            if (($status['status'] ?? '') === 'running') {
                $this->waitFor($server, $node, $this->call($server, 'post', "nodes/{$node}/qemu/{$vmId}/status/stop"), $deadline);
            }

            $this->waitFor($server, $node, $this->call($server, 'delete', "nodes/{$node}/qemu/{$vmId}", ['purge' => 1, 'destroy-unreferenced-disks' => 1]), $deadline);

            return true;
        }, false, report: false);
    }

    /**
     * Ask Proxmox to stop a running task. Nothing happens when it already finished.
     */
    private function stopTask(?Server $server, string $node, mixed $task): void
    {
        if (is_string($task) && str_starts_with($task, 'UPID:')) {
            rescue(fn () => $this->call($server, 'delete', "nodes/{$node}/tasks/".rawurlencode($task)), report: false);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function ipAddresses(Service $service, array $config, bool $running): array
    {
        if (preg_match('/ip=([0-9.]+)\//', (string) ($config['ipconfig0'] ?? ''), $match)) {
            return [$match[1]];
        }

        if (! $running || empty($config['agent'])) {
            return [];
        }

        [$node, $vmId] = $this->vm($service);
        $interfaces = rescue(fn (): array => (array) ($this->call($service->server, 'get', "nodes/{$node}/qemu/{$vmId}/agent/network-get-interfaces")['result'] ?? []), [], report: false);
        $addresses = [];

        foreach ($interfaces as $interface) {
            foreach ((array) ($interface['ip-addresses'] ?? []) as $address) {
                $ip = (string) ($address['ip-address'] ?? '');

                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $addresses[] = $ip;
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * @return array<string, mixed>
     */
    private function status(Service $service): array
    {
        [$node, $vmId] = $this->vm($service);

        return (array) $this->call($service->server, 'get', "nodes/{$node}/qemu/{$vmId}/status/current");
    }

    /**
     * Wait until a Proxmox task (a "UPID:..." string) has finished, and fail if it did not succeed.
     * A task succeeded when it ended with "OK", or with "WARNINGS: n" (done, but it logged warnings).
     */
    private function waitFor(?Server $server, string $node, mixed $task, ?CarbonInterface $deadline = null): void
    {
        if (! is_string($task) || ! str_starts_with($task, 'UPID:')) {
            return;
        }

        $deadline ??= now()->addSeconds(self::TASK_TIMEOUT);

        do {
            $status = (array) $this->call($server, 'get', "nodes/{$node}/tasks/".rawurlencode($task).'/status');

            if (($status['status'] ?? '') === 'stopped') {
                $exit = trim((string) ($status['exitstatus'] ?? ''));

                if ($exit !== 'OK' && ! preg_match('/^WARNINGS: \d+$/', $exit)) {
                    throw new RuntimeException('Proxmox: '.($exit !== '' ? $exit : __('the task failed')));
                }

                return;
            }

            Sleep::for(3)->seconds();
        } while (now()->lessThan($deadline));

        throw new RuntimeException(__('Proxmox did not finish the task in time. Check the task log on the node.'));
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function vm(Service $service): array
    {
        $vmId = (int) $this->moduleValue($service, 'vmid', 0);

        if ($vmId === 0) {
            throw new RuntimeException(__('This service has no Proxmox VM ID yet.'));
        }

        return [(string) $this->moduleValue($service, 'node', $this->node($service)), $vmId];
    }

    private function node(Service $service): string
    {
        $node = (string) $this->productSetting($service, 'node', '');

        if (! preg_match('/^[A-Za-z0-9.-]+$/', $node)) {
            throw new RuntimeException(__('Set the Proxmox node name on the product first.'));
        }

        return $node;
    }

    private function vmName(Service $service): string
    {
        $domain = strtolower((string) $service->domain);

        return preg_match('/^[a-z0-9]([a-z0-9.-]{0,61}[a-z0-9])?$/', $domain) && str_contains($domain, '.') ? $domain : 'vps'.$service->id;
    }

    private function makePassword(): string
    {
        return Str::password(14, symbols: false).'a7';
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

    /**
     * Call the API and return its "data" value.
     *
     * @param  array<string, mixed>  $params
     */
    private function call(?Server $server, string $method, string $path, array $params = []): mixed
    {
        if ($server === null) {
            throw new RuntimeException(__('No server is assigned.'));
        }

        $url = ($server->use_ssl ? 'https' : 'http').'://'.$server->hostname.':'.($server->port ?: $this->defaultPort()).'/api2/json/'.$path;

        try {
            $request = Http::withHeaders(['Authorization' => 'PVEAPIToken='.$server->username.'='.$server->api_token])
                ->timeout(30)
                ->acceptJson()
                ->asForm();

            $response = match ($method) {
                'get' => $request->get($url, $params),
                'put' => $request->put($url, $params),
                'delete' => $request->delete($url.($params === [] ? '' : '?'.http_build_query($params))),
                default => $request->post($url, $params),
            };
        } catch (ConnectionException $exception) {
            throw new RuntimeException(__('Could not connect to :host: :error', ['host' => $server->hostname, 'error' => $exception->getMessage()]));
        }

        if ($response->status() === 401) {
            throw new RuntimeException(__('Proxmox rejected the API token.'));
        }

        if (! $response->successful()) {
            $details = collect((array) $response->json('errors', []))->map(fn (mixed $error, string|int $field): string => $field.': '.trim((string) $error))->implode(' ');

            throw new RuntimeException('Proxmox: '.trim($response->toPsrResponse()->getReasonPhrase().' '.$details));
        }

        return $response->json('data');
    }
}
