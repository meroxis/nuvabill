<?php

namespace Nuvabill\Extensions\Virtualizor;

use App\Contracts\HasClientPanel;
use App\Extensions\Servers\Module;
use App\Extensions\Servers\ModuleResult;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Virtualizor through its Admin API. Clients manage their VPS in the Nuvabill client area:
 * power, usage, IP addresses, OS reinstall, hostname, root password and VNC details.
 */
class VirtualizorModule extends Module implements HasClientPanel
{
    public function productFields(): array
    {
        return [
            'virt' => [
                'label' => 'Virtualization',
                'type' => 'select',
                'options' => ['kvm' => 'KVM', 'xen' => 'Xen', 'xenhvm' => 'Xen HVM', 'openvz' => 'OpenVZ', 'lxc' => 'LXC', 'proxk' => 'Proxmox KVM', 'proxl' => 'Proxmox LXC'],
                'required' => true,
            ],
            'plan_id' => [
                'label' => 'Virtualizor plan ID',
                'type' => 'text',
                'help' => 'Plans → List plans in Virtualizor. The plan sets RAM, disk, CPU and bandwidth.',
            ],
            'os_id' => [
                'label' => 'Operating system ID',
                'type' => 'text',
                'required' => true,
                'help' => 'Media → OS templates in Virtualizor, for example the ID of Ubuntu 24.04.',
            ],
            'num_ips' => ['label' => 'IPv4 addresses', 'type' => 'text', 'help' => 'Default 1.'],
            'ram' => ['label' => 'RAM (MB)', 'type' => 'text', 'help' => 'Only needed without a plan.'],
            'disk' => ['label' => 'Disk (GB)', 'type' => 'text', 'help' => 'Only needed without a plan.'],
            'cores' => ['label' => 'CPU cores', 'type' => 'text', 'help' => 'Only needed without a plan.'],
            'bandwidth' => ['label' => 'Bandwidth (GB, 0 = unlimited)', 'type' => 'text', 'help' => 'Only needed without a plan.'],
        ];
    }

    public function serverHelp(): string
    {
        return 'Hostname: the Virtualizor master server. Put the Admin API key in "API token" and the API password in "Password" (Virtualizor admin → Configuration → Server Info), and allow this server\'s IP there. Port 4085 needs a valid SSL certificate.';
    }

    public function defaultPort(): int
    {
        return 4085;
    }

    public function hasLoginLink(): bool
    {
        return false;
    }

    public function testConnection(Server $server): ModuleResult
    {
        return $this->attempt(function () use ($server): ModuleResult {
            $response = $this->call($server, ['act' => 'vs', 'reslen' => 1]);

            return ModuleResult::ok(__('Connected to Virtualizor. :count VPS found.', ['count' => (int) ($response['counts']['vs'] ?? count((array) ($response['vs'] ?? [])))]));
        });
    }

    public function create(Service $service): ModuleResult
    {
        return $this->attempt(function () use ($service): ModuleResult {
            $rootPassword = $this->makePassword();
            $disk = (int) $this->productSetting($service, 'disk', 0);

            $response = $this->call($service->server, ['act' => 'addvs'], array_filter([
                'addvps' => 1,
                'virt' => (string) $this->productSetting($service, 'virt', 'kvm'),
                'user_email' => $service->client->email,
                'user_pass' => $this->makePassword(),
                'hostname' => $this->hostnameFor($service),
                'rootpass' => $rootPassword,
                'osid' => (int) $this->productSetting($service, 'os_id'),
                'plid' => (int) $this->productSetting($service, 'plan_id', 0) ?: null,
                'num_ips' => (int) $this->productSetting($service, 'num_ips', 1) ?: 1,
                'ram' => (int) $this->productSetting($service, 'ram', 0) ?: null,
                'cores' => (int) $this->productSetting($service, 'cores', 0) ?: null,
                'bandwidth' => $this->productSetting($service, 'bandwidth') !== null ? (int) $this->productSetting($service, 'bandwidth') : null,
                'space' => $disk > 0 ? [['size' => $disk]] : null,
                'node_select' => 1,
            ], fn (mixed $value): bool => $value !== null), timeout: 180);

            $vpsId = $response['vs_info']['vpsid'] ?? $response['newvs']['vpsid'] ?? null;

            if (blank($vpsId)) {
                return ModuleResult::fail(__('Virtualizor did not return the new VPS ID.'));
            }

            return ModuleResult::ok(__('VPS #:id created.', ['id' => $vpsId]), [
                'username' => 'root',
                'password' => $rootPassword,
                'module_data' => ['vpsid' => (string) $vpsId, 'ips' => array_values((array) ($response['vs_info']['ips'] ?? []))],
            ]);
        });
    }

    public function suspend(Service $service, string $reason): ModuleResult
    {
        return $this->simple($service, ['act' => 'vs', 'suspend' => $this->vpsId($service)], ['suspend_reason' => $reason], __('VPS suspended.'));
    }

    public function unsuspend(Service $service): ModuleResult
    {
        return $this->simple($service, ['act' => 'vs', 'unsuspend' => $this->vpsId($service)], [], __('VPS unsuspended.'));
    }

    public function terminate(Service $service): ModuleResult
    {
        return $this->simple($service, ['act' => 'vs', 'delete' => $this->vpsId($service)], [], __('VPS deleted.'));
    }

    public function changePackage(Service $service): ModuleResult
    {
        $planId = (int) $this->productSetting($service, 'plan_id', 0);

        if ($planId === 0) {
            return ModuleResult::fail(__('Set a Virtualizor plan ID on the product first.'));
        }

        return $this->simple($service, ['act' => 'managevps', 'vpsid' => $this->vpsId($service)], ['editvps' => 1, 'plid' => $planId, 'apply_plan' => 1], __('Plan applied to the VPS.'));
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
            'hostname' => __('Change hostname'),
            'password' => __('Change root password'),
            'reinstall' => __('Reinstall OS'),
            'vnc' => __('Show VNC details'),
        ];
    }

    public function clientPanel(Service $service): array
    {
        $vpsId = $this->vpsId($service);
        $info = (array) ($this->call($service->server, ['act' => 'vs', 'vpsid' => $vpsId])['vs'][$vpsId] ?? []);
        $statusResponse = $this->call($service->server, ['act' => 'vs', 'vs_status' => [$vpsId]]);
        $status = (array) ($statusResponse['status'][$vpsId] ?? $statusResponse['vs_status'][$vpsId] ?? $statusResponse[$vpsId] ?? []);

        return [
            'hostname' => $info['hostname'] ?? $service->domain,
            'os' => $info['os_name'] ?? null,
            'ips' => array_values(array_filter((array) ($info['ips'] ?? $this->moduleValue($service, 'ips', [])), 'is_string')),
            'state' => match ((int) ($status['status'] ?? -1)) {
                1 => 'running',
                0 => 'stopped',
                2 => 'suspended',
                default => 'unknown',
            },
            'cpu' => isset($status['used_cpu']) ? (float) $status['used_cpu'] : null,
            'ram' => ['used' => (float) ($status['used_ram'] ?? 0), 'total' => (float) ($status['ram'] ?? $info['ram'] ?? 0), 'unit' => 'MB'],
            'disk' => ['used' => (float) ($status['used_disk'] ?? 0), 'total' => (float) ($status['disk'] ?? $info['space'] ?? 0), 'unit' => 'GB'],
            'bandwidth' => ['used' => (float) ($status['used_bandwidth'] ?? 0), 'total' => (float) ($status['bandwidth'] ?? $info['bandwidth'] ?? 0), 'unit' => 'GB'],
            'templates' => $this->templates($service->server, (string) ($info['virt'] ?? $this->productSetting($service, 'virt', 'kvm'))),
        ];
    }

    public function clientAction(Service $service, string $action, array $input): ModuleResult
    {
        $vpsId = $this->vpsId($service);

        return match ($action) {
            'start', 'stop', 'restart', 'poweroff' => $this->simple($service, ['act' => 'vs', 'action' => $action, 'vpsid' => $vpsId], [], match ($action) {
                'start' => __('The VPS is starting.'),
                'stop' => __('The VPS is shutting down.'),
                'restart' => __('The VPS is restarting.'),
                default => __('The VPS is powered off.'),
            }),
            'hostname' => $this->changeHostname($service, (string) ($input['hostname'] ?? '')),
            'password' => $this->changePassword($service, (string) ($input['password'] ?? '')),
            'reinstall' => $this->reinstall($service, (string) ($input['os_id'] ?? ''), (string) ($input['password'] ?? '')),
            'vnc' => $this->attempt(function () use ($service, $vpsId): ModuleResult {
                $info = (array) ($this->call($service->server, ['act' => 'vnc'], ['novnc' => $vpsId])['info'] ?? []);

                return filled($info['port'] ?? null)
                    ? ModuleResult::ok(__('VNC details are shown below.'), ['vnc' => ['ip' => $info['ip'] ?? '', 'port' => $info['port'], 'password' => $info['password'] ?? '']])
                    : ModuleResult::fail(__('VNC is not turned on for this VPS.'));
            }),
            default => ModuleResult::fail(__('This action is not available.')),
        };
    }

    private function changeHostname(Service $service, string $hostname): ModuleResult
    {
        $hostname = strtolower(trim($hostname));

        if (! preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $hostname)) {
            return ModuleResult::fail(__('Enter a hostname like server.example.com.'));
        }

        return $this->simple($service, ['act' => 'managevps', 'vpsid' => $this->vpsId($service)], ['editvps' => 1, 'hostname' => $hostname], __('Hostname changed. It takes effect after a restart.'));
    }

    private function changePassword(Service $service, string $password): ModuleResult
    {
        if (strlen($password) < 10 || ! preg_match('/[a-z]/i', $password) || ! preg_match('/\d/', $password)) {
            return ModuleResult::fail(__('Use at least 10 characters with letters and numbers.'));
        }

        $result = $this->simple($service, ['act' => 'managevps', 'vpsid' => $this->vpsId($service)], ['editvps' => 1, 'rootpass' => $password], __('Root password changed. It takes effect after a restart.'));

        if ($result->success) {
            $service->update(['password' => $password]);
        }

        return $result;
    }

    private function reinstall(Service $service, string $osId, string $password): ModuleResult
    {
        if (! array_key_exists($osId, $this->templates($service->server, (string) $this->productSetting($service, 'virt', 'kvm')))) {
            return ModuleResult::fail(__('Choose an operating system from the list.'));
        }

        if (strlen($password) < 10 || ! preg_match('/[a-z]/i', $password) || ! preg_match('/\d/', $password)) {
            return ModuleResult::fail(__('Use at least 10 characters with letters and numbers.'));
        }

        $result = $this->simple($service, ['act' => 'rebuild'], [
            'vpsid' => $this->vpsId($service),
            'osid' => $osId,
            'newpass' => $password,
            'conf' => $password,
            'reos' => 1,
        ], __('The reinstall has started. All data on the VPS is being erased.'));

        if ($result->success) {
            $service->update(['password' => $password]);
        }

        return $result;
    }

    /**
     * OS templates for the virtualization type, as ID => name. Kept for an hour.
     *
     * @return array<string, string>
     */
    private function templates(Server $server, string $virt): array
    {
        return Cache::remember('nuvabill.virtualizor.templates.'.$server->id.'.'.$virt, 3600, function () use ($server, $virt): array {
            $templates = [];

            foreach ((array) ($this->call($server, ['act' => 'ostemplates'])['ostemplates'] ?? []) as $id => $template) {
                $template = (array) $template;

                if (($template['type'] ?? $virt) === $virt) {
                    $templates[(string) ($template['osid'] ?? $id)] = (string) ($template['name'] ?? $template['filename'] ?? $id);
                }
            }

            asort($templates);

            return $templates;
        });
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $post
     */
    private function simple(Service $service, array $query, array $post, string $message): ModuleResult
    {
        return $this->attempt(function () use ($service, $query, $post, $message): ModuleResult {
            $response = $this->call($service->server, $query, $post);

            return empty($response['done']) && empty($response['vsop']) && empty($response['vs_info'])
                ? ModuleResult::fail(__('Virtualizor did not confirm the action.'))
                : ModuleResult::ok($message);
        });
    }

    private function vpsId(Service $service): string
    {
        $id = (string) $this->moduleValue($service, 'vpsid', '');

        if ($id === '') {
            throw new RuntimeException(__('This service has no Virtualizor VPS ID yet.'));
        }

        return $id;
    }

    private function hostnameFor(Service $service): string
    {
        $domain = strtolower((string) $service->domain);

        return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) ? $domain : 'vps'.$service->id.'.localdomain';
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
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function call(?Server $server, array $query, array $post = [], int $timeout = 30): array
    {
        if ($server === null) {
            throw new RuntimeException(__('No server is assigned.'));
        }

        $scheme = $server->use_ssl ? 'https' : 'http';
        $url = $scheme.'://'.$server->hostname.':'.($server->port ?: $this->defaultPort()).'/index.php';
        $query += ['api' => 'json', 'adminapikey' => (string) $server->api_token, 'adminapipass' => (string) $server->password];

        try {
            $request = Http::timeout($timeout)->acceptJson()->asForm();
            $response = $post === [] ? $request->get($url, $query) : $request->post($url.'?'.http_build_query($query), $post);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(__('Could not connect to :host: :error', ['host' => $server->hostname, 'error' => $exception->getMessage()]));
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException(__('Virtualizor returned HTTP :status. Check the API key, password and allowed IPs.', ['status' => $response->status()]));
        }

        if (! empty($data['error'])) {
            throw new RuntimeException('Virtualizor: '.implode(' ', array_map('strval', (array) $data['error'])));
        }

        return $data;
    }
}
