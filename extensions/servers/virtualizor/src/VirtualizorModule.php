<?php

namespace Nuvabill\Extensions\Virtualizor;

use App\Contracts\HasClientPanel;
use App\Enums\ServiceStatus;
use App\Extensions\Servers\Module;
use App\Extensions\Servers\ModuleResult;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceAddon;
use App\Support\Activity;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Virtualizor through its Admin API. Clients manage their VPS in the Nuvabill client area:
 * power, usage, IP addresses, OS reinstall, hostname and root password. A product can also let
 * clients open their own VPS in Virtualizor's end-user panel with one click.
 */
class VirtualizorModule extends Module implements HasClientPanel
{
    /**
     * Exception code: Virtualizor answered and refused the request, so it did nothing. Any other
     * failure (no connection, no clear answer) leaves it open whether the request ran.
     */
    private const REFUSED = 1;

    /**
     * Virtualizor's end-user panel port with SSL.
     */
    private const PANEL_PORT = 4083;

    /**
     * Virtualizor's other panel ports: the admin panel (4084, 4085) and plain HTTP (4081, 4082).
     * Panel sign-in never goes there: a sign-in call on an admin port could open the admin panel.
     */
    private const NOT_PANEL_PORTS = [4081, 4082, 4084, 4085];

    /**
     * The most VPS of one Virtualizor user that a panel sign-in checks. A user with more is refused.
     */
    private const USER_VPS_LIMIT = 100;

    /**
     * Control panels Virtualizor installs on a new VPS, by Virtualizor's own names.
     */
    private const CONTROL_PANELS = [
        'cpanel' => 'cPanel & WHM',
        'plesk' => 'Plesk',
        'webuzo' => 'Webuzo',
        'webmin' => 'Webmin',
        'inteworx' => 'InterWorx',
        'ispconfig' => 'ISPConfig',
        'cwp' => 'CentOS Web Panel',
        'vesta' => 'Vesta',
    ];

    /**
     * Product settings sent as whole numbers with a new VPS, with their smallest value and their label.
     */
    private const NUMBER_OPTIONS = [
        'num_ips6' => [0, 'IPv6 addresses'],
        'num_ips6_subnet' => [0, 'IPv6 subnets'],
        'network_speed' => [0, 'Network speed'],
        'upload_speed' => [0, 'Upload speed'],
        'osreinstall_limit' => [0, 'OS reinstalls a month'],
        'server_group' => [0, 'Virtualizor server group ID'],
        'slave_server' => [0, 'Virtualizor server ID'],
    ];

    /**
     * Where a resource is in Virtualizor's VPS details.
     */
    private const RESOURCE_FIELDS = ['cores' => 'cores', 'ram' => 'ram', 'disk' => 'space', 'num_ips' => 'ips', 'bandwidth' => 'bandwidth'];

    public function productFields(): array
    {
        $panels = array_map(fn (string $name): string => $name.' (license not included)', self::CONTROL_PANELS);

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
            'recipe_id' => [
                'label' => 'Recipe ID',
                'type' => 'text',
                'help' => 'Optional. A Virtualizor recipe (Recipes → List recipes) that runs once on a new VPS. Clients may run it again when they reinstall.',
            ],
            'control_panel' => [
                'label' => 'Control panel to install',
                'type' => 'select',
                'options' => ['' => 'None'] + $panels,
                'help' => 'Optional. Virtualizor installs it on a new VPS. The license is not included: buy it separately. The order needs a real hostname such as server.example.com, and the product no recipe.',
            ],
            'num_ips6' => ['label' => 'IPv6 addresses', 'type' => 'text', 'help' => 'Optional. Empty gives none.'],
            'num_ips6_subnet' => ['label' => 'IPv6 subnets', 'type' => 'text', 'help' => 'Optional. Empty gives none.'],
            'network_speed' => ['label' => 'Network speed (KB/s)', 'type' => 'text', 'help' => 'Optional. 0 = no limit. Empty uses the plan.'],
            'upload_speed' => ['label' => 'Upload speed (KB/s)', 'type' => 'text', 'help' => 'Optional. 0 = no limit. Empty uses the plan.'],
            'server_group' => ['label' => 'Virtualizor server group ID', 'type' => 'text', 'help' => 'Optional. New VPS go to a server in this group.'],
            'slave_server' => ['label' => 'Virtualizor server ID', 'type' => 'text', 'help' => 'Optional. New VPS go to this server (0 = the master). It wins over the server group.'],
            'osreinstall_limit' => ['label' => 'OS reinstalls a month', 'type' => 'text', 'help' => 'Optional. 0 = no limit.'],
            'client_login' => [
                'label' => 'Client panel sign-in',
                'type' => 'select',
                'options' => ['' => 'Off', '1' => 'On'],
                'help' => 'Clients get an "Open Virtualizor panel" button that signs them in to their own VPS, with its browser console. First turn off panel features that skip billing in Virtualizor (Configuration → Enduser settings).',
            ],
            'panel_port' => ['label' => 'Client panel port', 'type' => 'text', 'help' => 'Default 4083, Virtualizor\'s end-user port. Never the admin port (4085) or the server\'s API port: sign-in stays off with those. It needs a valid SSL certificate.'],
            'resource_addons' => [
                'label' => 'Resource add-ons (JSON)',
                'type' => 'textarea',
                'help' => 'Optional. Add-ons that give a VPS more resources, as compact JSON: {"version":1,"ids":{"cpu":5,"ram":6,"disk":7,"ipv4":8}} with your add-on IDs. Each adds 1 core, 2048 MB RAM, 40 GB disk or 1 IPv4; "steps":{"ram":1024} changes an amount. Other add-ons are left alone. A new VPS gets its extras only when its order invoice is paid.',
            ],
        ];
    }

    public function serverHelp(): string
    {
        return 'Hostname: the Virtualizor master server. Put the Admin API key in "API token" and the API password in "Password" (Virtualizor admin → Configuration → Server Info), and allow this server\'s IP there. Keep "Use SSL" on: port 4085 needs a valid SSL certificate. Without SSL the API key and password travel unencrypted, so only turn it off on a private network. Client panel sign-in always uses SSL and Virtualizor\'s end-user port 4083, unless the product sets another end-user port. It never uses the admin port.';
    }

    public function defaultPort(): int
    {
        return 4085;
    }

    /**
     * Off: the server panel shows its own "Open Virtualizor panel" button for products with client
     * panel sign-in on, so the page has no second, general button.
     */
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
            $record = ResourceAddons::record($service);

            // A create with resource add-ons was sent before: never send another one for this service.
            if ($record !== null && $record['state'] === ResourceAddons::REQUESTED) {
                $record = $this->reconcile($service, $record);
            }

            if ($record !== null && $record['vpsid'] !== null) {
                return $this->verifyResources($service, $record);
            }

            $options = $this->createOptions($service);
            $hostname = isset($options['control_panel']) ? $this->panelHostname($service, $options['control_panel']) : $this->hostnameFor($service);
            $rootPassword = $this->makePassword();
            $disk = (int) $this->productSetting($service, 'disk', 0);

            $post = array_filter([
                'addvps' => 1,
                'virt' => (string) $this->productSetting($service, 'virt', 'kvm'),
                'user_email' => $service->client->email,
                'user_pass' => $this->makePassword(),
                'hostname' => $hostname,
                'rootpass' => $rootPassword,
                'osid' => (int) $this->productSetting($service, 'os_id'),
                'plid' => (int) $this->productSetting($service, 'plan_id', 0) ?: null,
                'num_ips' => (int) $this->productSetting($service, 'num_ips', 1) ?: 1,
                'ram' => (int) $this->productSetting($service, 'ram', 0) ?: null,
                'cores' => (int) $this->productSetting($service, 'cores', 0) ?: null,
                'bandwidth' => $this->productSetting($service, 'bandwidth') !== null ? (int) $this->productSetting($service, 'bandwidth') : null,
                'space' => $disk > 0 ? [['size' => $disk]] : null,
                'node_select' => 1,
            ], fn (mixed $value): bool => $value !== null) + $options;

            // A chosen Virtualizor server replaces the automatic choice.
            if (isset($options['slave_server'])) {
                unset($post['node_select']);
            }

            $resources = $this->resources($service, forCreate: true);

            if ($resources !== null) {
                return $this->createWithResources($service, $post, $resources, $hostname, $rootPassword);
            }

            $response = $this->call($service->server, ['act' => 'addvs'], $post, timeout: 180);
            $vpsId = $response['vs_info']['vpsid'] ?? $response['newvs']['vpsid'] ?? null;

            if (blank($vpsId)) {
                return ModuleResult::fail(__('Virtualizor did not return the new VPS ID.'));
            }

            return ModuleResult::ok(__('VPS #:id created.', ['id' => $vpsId]), [
                'username' => 'root',
                'password' => $rootPassword,
                'module_data' => array_filter([
                    'vpsid' => (string) $vpsId,
                    'ips' => array_values((array) ($response['vs_info']['ips'] ?? [])),
                    'uid' => $this->userId($response['vs_info']['uid'] ?? null),
                ], fn (mixed $value): bool => $value !== null),
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

    /**
     * Applies the product's plan. A VPS with resource add-ons then gets its extras back on top of
     * the new plan, and the result is checked.
     */
    public function changePackage(Service $service): ModuleResult
    {
        $planId = (int) $this->productSetting($service, 'plan_id', 0);

        if ($planId === 0) {
            return ModuleResult::fail(__('Set a Virtualizor plan ID on the product first.'));
        }

        return $this->attempt(function () use ($service, $planId): ModuleResult {
            $resources = $this->resources($service, forCreate: false);

            if ($resources === null) {
                return $this->simple($service, ['act' => 'managevps', 'vpsid' => $this->vpsId($service)], ['editvps' => 1, 'plid' => $planId, 'apply_plan' => 1], __('Plan applied to the VPS.'));
            }

            return $this->changePackageWithResources($service, $planId, $resources['totals']);
        });
    }

    /**
     * A one-time sign-in link to Virtualizor's end-user panel for the client's own VPS. It is made
     * on every click, after checking again that the Virtualizor user behind the VPS has no VPS
     * except this client's. The link is never stored or logged.
     */
    public function loginUrl(Service $service): ?string
    {
        $server = $service->server;

        if ($service->status !== ServiceStatus::Active || $server === null || ! $this->signInPossible($service)) {
            return null;
        }

        $port = (int) $this->panelPort($service);

        try {
            $vpsId = $this->vpsId($service);

            if (! $this->ownedByClient($service, $vpsId)) {
                return null;
            }

            $response = $this->panelCall($server, $port, ['act' => 'sso', 'svs' => $vpsId]);
        } catch (RuntimeException $exception) {
            Activity::log('service.module_failed', "Panel sign-in for service #{$service->id} ({$service->label()}) failed: {$exception->getMessage()}", $service);

            return null;
        }

        $token = (string) ($response['token_key'] ?? '');
        $session = (string) ($response['sid'] ?? '');

        if (! preg_match('/\A[A-Za-z0-9_-]{8,128}\z/', $token) || ! preg_match('/\A[A-Za-z0-9_-]{8,256}\z/', $session)) {
            Activity::log('service.module_failed', "Panel sign-in for service #{$service->id} ({$service->label()}) failed: Virtualizor did not return a sign-in session.", $service);

            return null;
        }

        Activity::log('service.panel_login', "Client opened the Virtualizor panel of service #{$service->id} ({$service->label()})", $service);

        return $this->baseUrl($server, $port, true).'/'.$token.'/?'.http_build_query(['as' => $session, 'svs' => $vpsId], '', '&', PHP_QUERY_RFC3986);
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
        $info = $this->vpsInfo($service->server, $vpsId);
        $status = (array) ($this->call($service->server, ['act' => 'vs', 'vs_status' => $vpsId])['status'][$vpsId] ?? []);
        $state = $this->stateFrom($status['status'] ?? null);
        // The panel's console replaces VNC details only when the sign-in button really shows.
        $signIn = $this->signInPossible($service) && $this->mayOfferSignIn($service, $info);

        if ($state === 'unknown' && (int) ($info['suspended'] ?? 0) === 1) {
            $state = 'suspended';
        }

        // Without live figures the panel shows no usage rather than zeros.
        $live = $status !== [];

        return [
            'hostname' => $info['hostname'] ?? $service->domain,
            'os' => $info['os_name'] ?? null,
            'ips' => $this->addresses($info !== [] ? $info : ['ips' => $this->moduleValue($service, 'ips', [])]),
            'state' => $state,
            'cpu' => isset($status['used_cpu']) && is_numeric($status['used_cpu']) ? (float) $status['used_cpu'] : null,
            'ram' => $live ? ['used' => (float) ($status['used_ram'] ?? 0), 'total' => (float) ($status['ram'] ?? $info['ram'] ?? 0), 'unit' => 'MB'] : null,
            'disk' => $live ? ['used' => (float) ($status['used_disk'] ?? 0), 'total' => (float) ($status['disk'] ?? $info['space'] ?? 0), 'unit' => 'GB'] : null,
            'bandwidth' => $live ? ['used' => (float) ($status['used_bandwidth'] ?? 0), 'total' => (float) ($status['bandwidth'] ?? $info['bandwidth'] ?? 0), 'unit' => 'GB'] : null,
            'network' => isset($status['net_in']) || isset($status['net_out'])
                ? ['in' => max(0, (float) ($status['net_in'] ?? 0)), 'out' => max(0, (float) ($status['net_out'] ?? 0))]
                : null,
            'templates' => $this->templates($service->server, (string) ($info['virt'] ?? $this->productSetting($service, 'virt', 'kvm'))),
            'recipe' => $this->productRecipe($service) !== null,
            'login' => $signIn ? __('Open Virtualizor panel') : null,
            'console' => $signIn ? 'panel' : 'vnc',
        ];
    }

    public function clientAction(Service $service, string $action, array $input): ModuleResult
    {
        return match ($action) {
            'start', 'stop', 'restart', 'poweroff' => $this->power($service, $action),
            'hostname' => $this->changeHostname($service, (string) ($input['hostname'] ?? '')),
            'password' => $this->changePassword($service, (string) ($input['password'] ?? '')),
            'reinstall' => $this->reinstall($service, (string) ($input['os_id'] ?? ''), (string) ($input['password'] ?? ''), filter_var($input['run_recipe'] ?? false, FILTER_VALIDATE_BOOLEAN)),
            'vnc' => $this->signInAvailable($service)
                ? ModuleResult::fail(__('Open the Virtualizor panel to use the console in your browser.'))
                : $this->vnc($service),
            default => ModuleResult::fail(__('This action is not available.')),
        };
    }

    /**
     * Start, stop (a shutdown signal), restart or power off (a hard stop). The new state comes from
     * Virtualizor's answer, so the panel can show it at once or wait for it.
     */
    private function power(Service $service, string $action): ModuleResult
    {
        return $this->attempt(function () use ($service, $action): ModuleResult {
            $vpsId = $this->vpsId($service);
            $response = $this->call($service->server, ['act' => 'vs', 'action' => $action, 'vpsid' => $vpsId]);

            if (empty($response['done']) && empty($response['vsop'])) {
                return ModuleResult::fail(__('Virtualizor did not confirm the action.'));
            }

            return ModuleResult::ok(match ($action) {
                'start' => __('The VPS is starting.'),
                'stop' => __('A shutdown signal was sent. The VPS stops once its system has shut down. If it does not stop, use Power off.'),
                'restart' => __('The VPS is restarting.'),
                default => __('The VPS is powered off.'),
            }, ['pending' => $action, 'state' => $this->stateFrom($response['vsop']['status'][$vpsId] ?? null)]);
        });
    }

    private function vnc(Service $service): ModuleResult
    {
        return $this->attempt(function () use ($service): ModuleResult {
            $info = (array) ($this->call($service->server, ['act' => 'vnc'], ['novnc' => $this->vpsId($service)])['info'] ?? []);

            return filled($info['port'] ?? null)
                ? ModuleResult::ok(__('VNC details are shown below.'), ['vnc' => ['ip' => (string) ($info['ip'] ?? ''), 'port' => (string) $info['port'], 'password' => (string) ($info['password'] ?? '')]])
                : ModuleResult::fail(__('VNC is not turned on for this VPS.'));
        });
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
        if (! $this->strongPassword($password)) {
            return ModuleResult::fail(__('Use at least 10 characters with letters and numbers.'));
        }

        $result = $this->simple($service, ['act' => 'managevps', 'vpsid' => $this->vpsId($service)], ['editvps' => 1, 'rootpass' => $password], __('Root password changed. It takes effect after a restart.'));

        if ($result->success) {
            $service->update(['password' => $password]);
        }

        return $result;
    }

    private function reinstall(Service $service, string $osId, string $password, bool $runRecipe): ModuleResult
    {
        if (! array_key_exists($osId, $this->templates($service->server, (string) $this->productSetting($service, 'virt', 'kvm')))) {
            return ModuleResult::fail(__('Choose an operating system from the list.'));
        }

        if (! $this->strongPassword($password)) {
            return ModuleResult::fail(__('Use at least 10 characters with letters and numbers.'));
        }

        // Only the product's own recipe, set by staff, can run again.
        $recipe = $runRecipe ? $this->productRecipe($service) : null;

        $result = $this->simple($service, ['act' => 'rebuild'], array_filter([
            'vpsid' => $this->vpsId($service),
            'osid' => $osId,
            'newpass' => $password,
            'conf' => $password,
            'reos' => 1,
            'recipe' => $recipe,
        ], fn (mixed $value): bool => $value !== null), __('The reinstall has started. All data on the VPS is being erased.'));

        if ($result->success) {
            $service->update(['password' => $password]);
        }

        return $result;
    }

    private function strongPassword(string $password): bool
    {
        return strlen($password) >= 10 && preg_match('/[a-z]/i', $password) && preg_match('/\d/', $password);
    }

    /**
     * The optional product settings for a new VPS, checked. Empty settings send nothing, so the
     * request is the same as without them.
     *
     * @return array<string, int|string>
     */
    private function createOptions(Service $service): array
    {
        $options = [];
        $recipe = $this->productNumber($service, 'recipe_id', 1, 'Recipe ID');
        $panel = trim((string) $this->productSetting($service, 'control_panel', ''));

        if ($panel !== '' && ! array_key_exists($panel, self::CONTROL_PANELS)) {
            throw new RuntimeException(__('Choose a control panel from the list on the product, or none.'));
        }

        if ($recipe !== null && $panel !== '') {
            throw new RuntimeException(__('Choose either a recipe or a control panel on the product, not both.'));
        }

        if ($recipe !== null) {
            $options['recipe'] = $recipe;
        }

        if ($panel !== '') {
            $options['control_panel'] = $panel;
        }

        foreach (self::NUMBER_OPTIONS as $key => [$min, $label]) {
            $value = $this->productNumber($service, $key, $min, $label);

            if ($value !== null) {
                $options[$key] = $value;
            }
        }

        return $options;
    }

    /**
     * A whole-number product setting, null when it is empty.
     */
    private function productNumber(Service $service, string $key, int $min, string $label): ?int
    {
        $value = $this->productSetting($service, $key);

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        $number = is_int($value) || is_string($value) ? filter_var(trim((string) $value), FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => 2147483647]]) : false;

        if ($number === false) {
            throw new RuntimeException(__('Set ":field" on the product to a whole number of :min or more, or leave it empty.', ['field' => $label, 'min' => $min]));
        }

        return $number;
    }

    /**
     * The product's recipe, or null when it has none or an invalid one.
     */
    private function productRecipe(Service $service): ?int
    {
        try {
            return $this->productNumber($service, 'recipe_id', 1, 'Recipe ID');
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * The hostname for a VPS that gets a control panel: the order's own domain, which must be a
     * real server name. cPanel has stricter rules.
     */
    private function panelHostname(Service $service, string $panel): string
    {
        $hostname = strtolower(trim((string) $service->domain));
        $labels = explode('.', $hostname);
        $valid = strlen($hostname) <= 253
            && filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/D', $hostname)
            && ! in_array(end($labels), ['localhost', 'localdomain', 'local', 'invalid', 'test', 'example'], true);

        if ($valid && $panel === 'cpanel') {
            $valid = strlen($hostname) <= 60
                && preg_match('/^[a-z]/', $hostname)
                && ! str_starts_with($hostname, 'www')
                && ! in_array($labels[0], ['cpanel', 'whm', 'webmail', 'webdisk', 'autoconfig', 'autodiscover', 'cpcalendars', 'cpcontacts'], true);
        }

        if (! $valid) {
            throw new RuntimeException($panel === 'cpanel'
                ? __('Set a valid cPanel server hostname such as server.example.com (60 characters at most) on the order. Local names, www and service names such as cpanel or webmail are not allowed.')
                : __('Set a real server hostname such as server.example.com on the order, so the control panel can be installed.'));
        }

        return $hostname;
    }

    /**
     * The extra resources the service's add-ons give under its product's policy, with the totals
     * to set on the VPS. Null when there are none: a service without add-ons, without a policy or
     * with only other add-ons works as before. Throws when the extras cannot be worked out safely.
     *
     * @return array{totals: array<string, int>, addons: list<int>, pool: int|null}|null
     */
    private function resources(Service $service, bool $forCreate): ?array
    {
        $record = ResourceAddons::record($service);

        if ($record === null && ! $service->addons()->where('status', ServiceAddon::STATUS_ACTIVE)->exists()) {
            return null;
        }

        $policy = ResourceAddons::policy($service);
        $extras = $policy === null ? ['add' => [], 'addons' => [], 'rows' => []] : ResourceAddons::extras($service, $policy);

        // On a plan change, add-ons that gave this VPS extras when it was made must still count.
        if (! $forCreate && $record !== null && $record['state'] === ResourceAddons::VERIFIED) {
            $active = $service->addons()->where('status', ServiceAddon::STATUS_ACTIVE)->pluck('product_addon_id')->map(fn (mixed $id): int => (int) $id)->all();

            if (array_diff(array_intersect($record['addons'], $active), $extras['addons']) !== []) {
                throw new RuntimeException(__('This VPS has extra resources from add-ons that the new plan\'s add-on policy does not cover, so the plan was not applied on the server. Set its resources in Virtualizor by hand.'));
            }
        }

        if ($policy === null || $extras['add'] === []) {
            return null;
        }

        if ($policy['server_id'] !== null && $policy['server_id'] !== (int) $service->server_id) {
            throw new RuntimeException(__('The resource add-on policy of this product is for another server, so nothing was changed. Check the product.'));
        }

        if ($policy['plan_id'] !== null && $policy['plan_id'] !== (int) $this->productSetting($service, 'plan_id', 0)) {
            throw new RuntimeException(__('The resource add-on policy of this product names another plan than the product, so nothing was changed. Check the product.'));
        }

        if ($forCreate) {
            ResourceAddons::assertPaid($service, $extras['rows']);
        }

        return ['totals' => $this->resourceTotals($service, $policy, $extras['add']), 'addons' => $extras['addons'], 'pool' => $policy['public_pool_id']];
    }

    /**
     * The totals for the resources that add-ons raise: the product's base plus the extras. The
     * base comes from the policy, the product settings or the Virtualizor plan, in that order.
     *
     * @param  array<string, mixed>  $policy
     * @param  array<string, int>  $add
     * @return array<string, int>
     */
    private function resourceTotals(Service $service, array $policy, array $add): array
    {
        $planId = (int) $this->productSetting($service, 'plan_id', 0);
        $plan = null;

        // A policy that lists the plan's base or an IP pool is checked against the live plan.
        if ($planId > 0 && ($policy['base'] !== [] || $policy['public_pool_id'] !== null)) {
            $plan = $this->plan($service->server, $planId);
            $this->checkPlan($service, $policy, $plan);
        }

        $totals = [];

        foreach ($add as $field => $extra) {
            $base = $policy['base'][$field] ?? $this->productBase($service, $field);

            if ($base === null && $planId > 0) {
                $plan ??= $this->plan($service->server, $planId);
                $value = filter_var($plan[self::RESOURCE_FIELDS[$field]] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $base = $value === false ? null : $value;
            }

            if ($base === null) {
                throw new RuntimeException(__('The base :resource of this product is not known, so the add-on extras cannot be added. Set it in the product\'s add-on policy ("base"). Nothing was changed.', ['resource' => $this->resourceName($field)]));
            }

            $totals[$field] = $base + $extra;

            if ($totals[$field] > $policy['max'][$field]) {
                throw new RuntimeException(__('With its add-ons this VPS would get more :resource than the product\'s add-on policy allows ("max"). Nothing was changed.', ['resource' => $this->resourceName($field)]));
            }
        }

        return $totals;
    }

    /**
     * The base of a resource from the product settings, or null when the plan sets it.
     */
    private function productBase(Service $service, string $field): ?int
    {
        if ($field === 'num_ips') {
            return (int) $this->productSetting($service, 'num_ips', 1) ?: 1;
        }

        $value = (int) $this->productSetting($service, $field, 0);

        return $value > 0 ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $plan
     */
    private function checkPlan(Service $service, array $policy, array $plan): void
    {
        $virt = (string) $this->productSetting($service, 'virt', '');

        if ($virt !== '' && isset($plan['virt']) && (string) $plan['virt'] !== $virt) {
            throw new RuntimeException(__('The Virtualizor plan is for another virtualization type than the product, so nothing was changed. Check the product.'));
        }

        foreach ($policy['base'] as $field => $value) {
            if (filter_var($plan[self::RESOURCE_FIELDS[$field]] ?? null, FILTER_VALIDATE_INT) !== $value) {
                throw new RuntimeException(__('The Virtualizor plan no longer matches the base in the product\'s add-on policy. Update one of them; nothing was changed.'));
            }
        }

        if ($policy['public_pool_id'] !== null && $this->poolIds($plan['ippoolid'] ?? null) !== [$policy['public_pool_id']]) {
            throw new RuntimeException(__('The Virtualizor plan does not use exactly the IP pool in the product\'s add-on policy, so nothing was changed.'));
        }
    }

    /**
     * IP pool IDs from a plan. Virtualizor keeps them as a PHP-serialized list; they are read with a
     * pattern, never unserialized.
     *
     * @return list<int>
     */
    private function poolIds(mixed $value): array
    {
        if (is_string($value) && preg_match('/\Aa:\d+:\{(.*)\}\z/s', $value, $match)) {
            preg_match_all('/i:\d+;(?:i:(\d+)|s:\d+:"(\d+)");/', $match[1], $pairs, PREG_SET_ORDER);
            $value = array_map(fn (array $pair): string => $pair[2] ?? $pair[1], $pairs);
        } elseif (is_string($value) || is_int($value)) {
            $value = [$value];
        }

        return array_values(array_map('intval', array_filter((array) $value, fn (mixed $id): bool => (is_int($id) || is_string($id)) && ctype_digit((string) $id) && (int) $id > 0)));
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(Server $server, int $planId): array
    {
        $plan = $this->call($server, ['act' => 'plans', 'reslen' => 500])['plans'][$planId] ?? null;

        if (! is_array($plan) || (int) ($plan['plid'] ?? $planId) !== $planId) {
            throw new RuntimeException(__('Virtualizor did not list plan :id, so the add-on extras cannot be worked out. Nothing was changed.', ['id' => $planId]));
        }

        return $plan;
    }

    /**
     * Create a VPS with add-on extras. The request is noted before it is sent, so a request without
     * a clear answer is never sent again: the next try looks for the VPS it may have made.
     *
     * @param  array<string, mixed>  $post
     * @param  array{totals: array<string, int>, addons: list<int>, pool: int|null}  $resources
     */
    private function createWithResources(Service $service, array $post, array $resources, string $hostname, string $rootPassword): ModuleResult
    {
        foreach ($resources['totals'] as $field => $total) {
            $post[$field === 'disk' ? 'space' : $field] = $field === 'disk' ? [['size' => $total]] : $total;
        }

        // The root password stays encrypted in the note until the VPS is checked, so a pending
        // service never shows it. The email is the one Virtualizor files the VPS under.
        $record = ResourceAddons::remember($service, [
            'state' => ResourceAddons::REQUESTED,
            'vpsid' => null,
            'hostname' => $hostname,
            'email' => strtolower((string) $post['user_email']),
            'totals' => $resources['totals'],
            'addons' => $resources['addons'],
            'pool' => $resources['pool'],
            'requested_at' => now()->getTimestamp(),
        ], $rootPassword);

        try {
            $response = $this->call($service->server, ['act' => 'addvs'], $post, timeout: 180);
        } catch (RuntimeException $exception) {
            if ($exception->getCode() === self::REFUSED) {
                ResourceAddons::forget($service);

                return ModuleResult::fail(__('Virtualizor refused to create the VPS, so none was made. Check the plan, the OS template and free IP addresses in Virtualizor, then try again.'));
            }

            return ModuleResult::fail($this->unclearCreate($hostname));
        }

        $vpsId = $response['vs_info']['vpsid'] ?? $response['newvs']['vpsid'] ?? null;

        if (! is_scalar($vpsId) || ! ctype_digit((string) $vpsId)) {
            return ModuleResult::fail($this->unclearCreate($hostname));
        }

        $record = ResourceAddons::remember($service, ['state' => ResourceAddons::CREATED, 'vpsid' => (string) $vpsId] + $record);

        return $this->verifyResources($service, $record);
    }

    /**
     * After a create request without a clear answer: the one VPS it made, or null when Virtualizor
     * shows none was made and enough time has passed to try again. Throws while it is unclear.
     *
     * A VPS made by the request has its hostname and the email it was sent with (kept in the note,
     * as the client may change theirs). VPS that other services already use are not it.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    private function reconcile(Service $service, array $record): ?array
    {
        $hostname = strtolower((string) ($record['hostname'] ?? ''));

        if ($hostname === '') {
            throw new RuntimeException($this->unclearCreate(__('(unknown)')));
        }

        $reslen = 50;
        $listed = [];

        foreach ((array) ($this->call($service->server, ['act' => 'vs', 'search' => 1, 'vpshostname' => $hostname, 'page' => 1, 'reslen' => $reslen])['vs'] ?? []) as $key => $vps) {
            $vpsId = is_array($vps) ? (string) ($vps['vpsid'] ?? $key) : '';

            if (! ctype_digit($vpsId)) {
                throw new RuntimeException($this->unclearCreate($hostname));
            }

            $listed[$vpsId] = ['hostname' => strtolower(trim((string) ($vps['hostname'] ?? ''))), 'email' => strtolower(trim((string) ($vps['email'] ?? '')))];
        }

        $email = strtolower((string) ($record['email'] ?? $service->client->email));
        $free = array_diff_key($listed, array_flip($this->vpsIdsOfOtherServices($service, array_map('strval', array_keys($listed)))));
        $mine = array_filter($free, fn (array $vps): bool => $vps['email'] === $email);
        $named = array_filter($mine, fn (array $vps): bool => $vps['hostname'] === $hostname);

        if (count($named) === 1) {
            return ResourceAddons::remember($service, ['state' => ResourceAddons::CREATED, 'vpsid' => (string) array_key_first($named)] + $record);
        }

        $age = isset($record['requested_at']) ? now()->getTimestamp() - (int) $record['requested_at'] : PHP_INT_MAX;

        // Only a full list from a search that worked shows that no VPS of this client has the name:
        // every VPS in it has a user email and the name, and the list was not cut short.
        $complete = count($listed) < $reslen && array_filter($listed, fn (array $vps): bool => $vps['email'] === '' || ! str_contains($vps['hostname'], $hostname)) === [];

        // Well after the request, none was made: Virtualizor has no VPS of this client with that name.
        if ($mine === [] && $complete && $age >= 600) {
            ResourceAddons::forget($service);

            return null;
        }

        throw new RuntimeException($this->unclearCreate($hostname));
    }

    /**
     * Which of the VPS IDs other services on the same server already use.
     *
     * @param  list<string>  $vpsIds
     * @return list<string>
     */
    private function vpsIdsOfOtherServices(Service $service, array $vpsIds): array
    {
        if ($vpsIds === []) {
            return [];
        }

        $used = Service::query()
            ->whereKeyNot($service->id)
            ->where('server_id', $service->server_id)
            ->whereIn('module_data->vpsid', $vpsIds)
            ->get(['id', 'module_data'])
            ->map(fn (Service $other): string => (string) ($other->module_data['vpsid'] ?? ''))
            ->all();

        return array_values(array_intersect($vpsIds, $used));
    }

    private function unclearCreate(string $hostname): string
    {
        return __('The create request for this VPS had no clear answer, so no second VPS is made yet. Look in Virtualizor for a VPS named :hostname: when this client has exactly one, the next try takes it; when there is none, a try after 10 minutes makes a new one. If you cancel the order instead, delete that VPS in Virtualizor.', ['hostname' => $hostname]);
    }

    /**
     * Check a VPS made with add-on extras before the service is activated: its name, resources and
     * IP addresses must match what was paid. A VPS that does not match stays pending for staff.
     *
     * @param  array<string, mixed>  $record
     */
    private function verifyResources(Service $service, array $record): ModuleResult
    {
        $vpsId = (string) $record['vpsid'];

        try {
            $info = $this->vpsInfo($service->server, $vpsId);
            $problems = $this->mismatches($service, $record, $info);

            if ($problems === [] && $record['pool'] !== null) {
                foreach ($this->addresses($info, FILTER_FLAG_IPV4) as $ip) {
                    if (! $this->inPool($service->server, $ip, $vpsId, (int) $record['pool'])) {
                        $problems[] = __('IP pool');
                        break;
                    }
                }
            }
        } catch (RuntimeException) {
            return ModuleResult::fail(__('VPS #:id was made but could not be checked yet. Try again; no second VPS is made. If you cancel the order instead, delete VPS #:id in Virtualizor.', ['id' => $vpsId]));
        }

        if ($problems !== []) {
            return ModuleResult::fail(__('VPS #:id does not match the paid add-on resources (:problems). It stays pending: fix it in Virtualizor, then try again. No second VPS is made. If you cancel the order instead, delete VPS #:id in Virtualizor.', ['id' => $vpsId, 'problems' => implode(', ', $problems)]));
        }

        $uid = $this->userId($info['uid'] ?? null);
        // Notes from before 1.1.0 left the root password on the service itself.
        $password = ResourceAddons::password($service) ?? (filled($service->password) ? (string) $service->password : null);
        ResourceAddons::remember($service, ['state' => ResourceAddons::VERIFIED] + $record);

        return ModuleResult::ok(__('VPS #:id created with its add-on resources.', ['id' => $vpsId]), array_filter([
            'username' => 'root',
            'password' => $password,
            'module_data' => array_filter(['vpsid' => $vpsId, 'ips' => $this->addresses($info), 'uid' => $uid], fn (mixed $value): bool => $value !== null),
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * What on the VPS differs from the record, as names for staff.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $info
     * @return list<string>
     */
    private function mismatches(Service $service, array $record, array $info): array
    {
        if ($info === []) {
            return [__('VPS not found')];
        }

        $problems = [];

        if (filled($record['hostname'] ?? null) && strtolower((string) ($info['hostname'] ?? '')) !== strtolower((string) $record['hostname'])) {
            $problems[] = __('hostname');
        }

        $virt = (string) $this->productSetting($service, 'virt', '');

        if ($virt !== '' && isset($info['virt']) && (string) $info['virt'] !== $virt) {
            $problems[] = __('virtualization');
        }

        foreach ((array) $record['totals'] as $field => $total) {
            if (array_key_exists($field, self::RESOURCE_FIELDS) && $this->resourceOf($info, $field) !== (float) $total) {
                $problems[] = $this->resourceName($field);
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function resourceOf(array $info, string $field): ?float
    {
        if ($field === 'num_ips') {
            return (float) count($this->addresses($info, FILTER_FLAG_IPV4));
        }

        $value = $info[self::RESOURCE_FIELDS[$field]] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private function resourceName(string $field): string
    {
        return match ($field) {
            'cores' => __('CPU cores'),
            'ram' => __('RAM'),
            'disk' => __('disk space'),
            'num_ips' => __('IPv4 addresses'),
            default => __('bandwidth'),
        };
    }

    /**
     * Whether Virtualizor's IP list shows the address as this VPS's public IPv4 from the pool.
     */
    private function inPool(Server $server, string $ip, string $vpsId, int $pool): bool
    {
        $rows = array_values(array_filter((array) ($this->call($server, ['act' => 'ips', 'ipsearch' => $ip, 'ippid' => $pool, 'page' => 1, 'reslen' => 50])['ips'] ?? []), fn (mixed $row): bool => is_array($row) && ($row['ip'] ?? null) === $ip));

        return count($rows) === 1
            && (int) ($rows[0]['ippid'] ?? 0) === $pool
            && (string) ($rows[0]['vpsid'] ?? '') === $vpsId
            && (int) ($rows[0]['ipv6'] ?? 1) === 0
            && (int) ($rows[0]['internal'] ?? 1) === 0
            && (int) ($rows[0]['nat'] ?? 1) === 0;
    }

    /**
     * Apply the new plan, then set the add-on extras on top again and check the result.
     *
     * @param  array<string, int>  $totals
     */
    private function changePackageWithResources(Service $service, int $planId, array $totals): ModuleResult
    {
        $server = $service->server;
        $vpsId = $this->vpsId($service);
        $before = $this->vpsInfo($server, $vpsId);
        $response = $this->call($server, ['act' => 'managevps', 'vpsid' => $vpsId], ['editvps' => 1, 'plid' => $planId, 'apply_plan' => 1]);

        if (empty($response['done']) && empty($response['vs_info'])) {
            return ModuleResult::fail(__('Virtualizor did not confirm the action.'));
        }

        try {
            $after = $this->vpsInfo($server, $vpsId);
            [$post, $problems] = $this->resourceEdits($totals, $before, $after, (array) ($response['vs_info']['disks'] ?? []));

            if ($post !== []) {
                $this->call($server, ['act' => 'managevps', 'vpsid' => $vpsId], ['editvps' => 1] + $post);
                $after = $this->vpsInfo($server, $vpsId);
            }

            $problems = array_values(array_unique([...$problems, ...$this->mismatches($service, ['totals' => $totals], $after)]));
        } catch (RuntimeException) {
            $problems = [__('no clear answer from Virtualizor')];
        }

        if ($problems === []) {
            return ModuleResult::ok(__('Plan applied to the VPS, with its add-on extras.'));
        }

        return ModuleResult::fail(__('The new plan is applied, but its add-on extras could not all be set again (:problems). Set them in Virtualizor by hand: :totals.', [
            'problems' => implode(', ', $problems),
            'totals' => collect($totals)->map(fn (int $total, string $field): string => $this->resourceName($field).' '.$total)->implode(', '),
        ]));
    }

    /**
     * The changes that bring the VPS back to the totals, and what cannot be set safely.
     *
     * @param  array<string, int>  $totals
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<int|string, mixed>  $disks
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function resourceEdits(array $totals, array $before, array $after, array $disks): array
    {
        $post = [];
        $problems = [];

        foreach ($totals as $field => $total) {
            if ($this->resourceOf($after, $field) === (float) $total) {
                continue;
            }

            if ($field === 'num_ips') {
                // Keep the addresses the VPS had, so clients do not get new ones.
                $kept = array_slice($this->addresses($before, FILTER_FLAG_IPV4), 0, $total);
                $post['num_ips'] = $total;

                if (count($kept) === $total) {
                    $post['ips'] = $kept;
                }
            } elseif ($field === 'disk') {
                $disks = array_values(array_filter($disks, 'is_array'));

                if (count($disks) !== 1 || blank($disks[0]['st_uuid'] ?? null)) {
                    $problems[] = $this->resourceName('disk');

                    continue;
                }

                $post['space'] = [array_filter(['size' => $total, 'st_uuid' => (string) $disks[0]['st_uuid'], 'disk_uuid' => isset($disks[0]['disk_uuid']) ? (string) $disks[0]['disk_uuid'] : null], fn (mixed $value): bool => $value !== null)];
            } else {
                $post[$field] = $total;
            }
        }

        return [$post, $problems];
    }

    /**
     * Whether the Virtualizor user behind the VPS has only this client's VPS on this server, so a
     * panel sign-in shows nothing else. Checked live on every click; a refusal is logged for staff.
     * Services from before 1.1.0 have no stored user yet: it is stored once the check passes.
     */
    private function ownedByClient(Service $service, string $vpsId): bool
    {
        $server = $service->server;
        $info = $this->vpsInfo($server, $vpsId);
        $uid = $this->userId($info['uid'] ?? null);
        $stored = $this->userId($this->moduleValue($service, 'uid'));
        $refuse = function (string $reason) use ($service): bool {
            Activity::log('service.panel_login_refused', "Panel sign-in for service #{$service->id} ({$service->label()}) refused: {$reason}", $service);

            return false;
        };

        if ($uid === null) {
            return $refuse('Virtualizor did not list the VPS with its user.');
        }

        if ((int) ($info['suspended'] ?? 0) !== 0) {
            return $refuse('the VPS is suspended in Virtualizor.');
        }

        if ($stored !== null && $stored !== $uid) {
            return $refuse("the VPS now belongs to Virtualizor user {$uid}, not {$stored}.");
        }

        $user = $this->call($server, ['act' => 'users', 'uid' => $uid], ['uid' => $uid])['users'][$uid] ?? null;
        $count = is_array($user) ? filter_var($user['numvps'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        if (! is_array($user) || $this->userId($user['uid'] ?? null) !== $uid || (string) ($user['type'] ?? '') !== '0' || $count === false) {
            return $refuse("Virtualizor user {$uid} is not a normal end user with a known number of VPS.");
        }

        // The user's VPS, listed one by one: the list must hold this VPS and agree with the count.
        $listed = $this->userVps($server, $user, $uid);

        if ($listed === null || ! in_array($vpsId, $listed, true) || count($listed) !== $count) {
            return $refuse("Virtualizor's list of the VPS of user {$uid} could not be checked or does not match its VPS count.");
        }

        // Every VPS of that user must be one of this client's live VPS on this server.
        $own = Service::query()
            ->where('client_id', $service->client_id)
            ->where('server_id', $server->id)
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])
            ->whereHas('product', fn ($query) => $query->where('server_module', $this->slug()))
            ->get()
            ->map(fn (Service $other): string => (string) ($other->module_data['vpsid'] ?? ''))
            ->filter(fn (string $id): bool => ctype_digit($id))
            ->push($vpsId)
            ->all();

        if (array_diff($listed, $own) !== []) {
            return $refuse("Virtualizor user {$uid} has VPS that are not this client's.");
        }

        if ($stored === null) {
            $service->forceFill(['module_data' => array_merge((array) $service->module_data, ['uid' => $uid])])->save();
        }

        return true;
    }

    /**
     * The IDs of all VPS of a Virtualizor user: searched by the user's email and, when that finds
     * none, by the user ID. Null when the list cannot be trusted: a VPS of another user or without
     * its user is in it (the search did not work), or it may be cut short.
     *
     * @param  array<string, mixed>  $user
     * @return list<string>|null
     */
    private function userVps(Server $server, array $user, string $uid): ?array
    {
        $email = is_string($user['email'] ?? null) && filter_var($user['email'], FILTER_VALIDATE_EMAIL) !== false ? $user['email'] : null;

        foreach (array_filter([$email, $uid]) as $search) {
            $rows = (array) ($this->call($server, ['act' => 'vs', 'search' => 1, 'user' => $search, 'page' => 1, 'reslen' => self::USER_VPS_LIMIT])['vs'] ?? []);

            if ($rows === []) {
                continue;
            }

            if (count($rows) >= self::USER_VPS_LIMIT) {
                return null;
            }

            $ids = [];

            foreach ($rows as $key => $row) {
                $id = is_array($row) ? (string) ($row['vpsid'] ?? $key) : '';

                if (! ctype_digit($id) || $this->userId($row['uid'] ?? null) !== $uid) {
                    return null;
                }

                $ids[] = $id;
            }

            return array_values(array_unique($ids));
        }

        return [];
    }

    /**
     * A quick check for showing the sign-in button; the full check runs on the click.
     *
     * @param  array<string, mixed>  $info
     */
    private function mayOfferSignIn(Service $service, array $info): bool
    {
        $stored = $this->userId($this->moduleValue($service, 'uid'));
        $uid = $this->userId($info['uid'] ?? null);

        return $uid !== null
            && (int) ($info['suspended'] ?? 0) === 0
            && ($stored === null || $stored === $uid);
    }

    private function signInEnabled(Service $service): bool
    {
        return (string) $this->productSetting($service, 'client_login', '') === '1';
    }

    /**
     * Whether panel sign-in can work at all, before asking Virtualizor: it is on for the product,
     * the server uses SSL and has its API password, and the panel port is an end-user port.
     */
    private function signInPossible(Service $service): bool
    {
        $server = $service->server;

        return $this->signInEnabled($service)
            && $server !== null
            && $server->use_ssl === true
            && filled($server->password)
            && $this->panelPort($service) !== null;
    }

    /**
     * Whether the client can open the panel now, so its console replaces the VNC details.
     */
    private function signInAvailable(Service $service): bool
    {
        if (! $this->signInPossible($service)) {
            return false;
        }

        try {
            return $this->mayOfferSignIn($service, $this->vpsInfo($service->server, $this->vpsId($service)));
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * The end-user panel port, or null when the product sets an invalid one or a port of the admin
     * panel or the API: a sign-in there could open Virtualizor's admin panel. Staff see why once a day.
     */
    private function panelPort(Service $service): ?int
    {
        $value = $this->productSetting($service, 'panel_port');

        if ($value === null || (is_string($value) && trim($value) === '')) {
            $port = self::PANEL_PORT;
        } else {
            $port = is_int($value) || is_string($value) ? filter_var(trim((string) $value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) : false;

            if ($port === false) {
                return null;
            }
        }

        if (in_array($port, self::NOT_PANEL_PORTS, true) || $port === (int) ($service->server?->port ?: $this->defaultPort())) {
            if (Cache::add('nuvabill.virtualizor.admin-panel-port.'.$service->product_id.'.'.$port, true, 86400)) {
                Activity::log('service.module_failed', "Client panel sign-in is off for the product of service #{$service->id} ({$service->label()}): its client panel port {$port} is an admin or API port of Virtualizor. Set Virtualizor's end-user port (4083) as the client panel port.", $service);
            }

            return null;
        }

        return $port;
    }

    private function userId(mixed $value): ?string
    {
        return (is_int($value) || is_string($value)) && preg_match('/\A[1-9][0-9]{0,18}\z/', (string) $value) ? (string) $value : null;
    }

    /**
     * One VPS from Virtualizor's list, found by its ID, or an empty array when it is not listed.
     *
     * @return array<string, mixed>
     */
    private function vpsInfo(Server $server, string $vpsId): array
    {
        $info = $this->call($server, ['act' => 'vs', 'search' => 1, 'vpsid' => $vpsId, 'page' => 1, 'reslen' => 1])['vs'][$vpsId] ?? null;

        return is_array($info) && (string) ($info['vpsid'] ?? $vpsId) === $vpsId ? $info : [];
    }

    /**
     * The VPS's IP addresses, IPv4 first, or only one kind with a FILTER_FLAG_IPV4/IPV6 flag.
     *
     * @param  array<string, mixed>  $info
     * @return list<string>
     */
    private function addresses(array $info, int $flag = 0): array
    {
        $all = array_filter([...array_values((array) ($info['ips'] ?? [])), ...array_values((array) ($info['ips6'] ?? []))], 'is_string');
        $v4 = array_filter($all, fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false);
        $v6 = array_filter($all, fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false);

        return array_values(array_unique(match ($flag) {
            FILTER_FLAG_IPV4 => $v4,
            FILTER_FLAG_IPV6 => $v6,
            default => [...$v4, ...$v6],
        }));
    }

    private function stateFrom(mixed $status): string
    {
        return match (is_numeric($status) ? (int) $status : null) {
            1 => 'running',
            0 => 'stopped',
            2 => 'suspended',
            default => 'unknown',
        };
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

        if (! ctype_digit($id)) {
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
     * The address of the server's API or end-user panel, from the server's own hostname only.
     */
    private function baseUrl(Server $server, int $port, bool $https): string
    {
        $host = strtolower(trim((string) $server->hostname));
        $ip = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if (! $ip && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new RuntimeException(__('Check the hostname of the Virtualizor server.'));
        }

        return ($https ? 'https' : 'http').'://'.($ip && str_contains($host, ':') ? '['.$host.']' : $host).':'.$port;
    }

    /**
     * One Admin API call. The key and password go in the query string, as Virtualizor's own SDK
     * sends them, so they never go into a message: a failed connection names only the host, and
     * an error from Virtualizor is cleaned of them. Plain HTTP only when the server has SSL off.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function call(?Server $server, array $query, array $post = [], int $timeout = 30): array
    {
        if ($server === null) {
            throw new RuntimeException(__('No server is assigned.'));
        }

        $url = $this->baseUrl($server, (int) ($server->port ?: $this->defaultPort()), $server->use_ssl !== false).'/index.php';
        $query += ['api' => 'json', 'adminapikey' => (string) $server->api_token, 'adminapipass' => (string) $server->password];

        try {
            $request = Http::timeout($timeout)->acceptJson()->asForm()->withoutRedirecting();
            $response = $post === [] ? $request->get($url, $query) : $request->post($url.'?'.http_build_query($query), $post);
        } catch (Throwable) {
            throw new RuntimeException(__('Could not connect to :host.', ['host' => $server->hostname]));
        }

        return $this->answer($server, $response->json(), $response->status());
    }

    /**
     * One call to the end-user panel, signed with the admin API password the way Virtualizor's SDK
     * does it for admins: a random prefix and a hash, so the password itself is never sent.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function panelCall(Server $server, int $port, array $query): array
    {
        $salt = strtolower(Str::random(8));
        $query += ['api' => 'json', 'apikey' => $salt.md5((string) $server->password.$salt)];

        try {
            $response = Http::timeout(20)->acceptJson()->withoutRedirecting()->get($this->baseUrl($server, $port, true).'/index.php', $query);
        } catch (Throwable) {
            throw new RuntimeException(__('Could not connect to :host.', ['host' => $server->hostname]));
        }

        return $this->answer($server, $response->json(), $response->status());
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(Server $server, mixed $data, int $status): array
    {
        if (! is_array($data)) {
            throw new RuntimeException(__('Virtualizor returned HTTP :status. Check the API key, password and allowed IPs.', ['status' => $status]));
        }

        if (! empty($data['error'])) {
            $errors = array_filter(Arr::flatten((array) $data['error']), fn (mixed $error): bool => is_scalar($error));

            throw new RuntimeException('Virtualizor: '.$this->clean($server, implode(' ', array_map('strval', $errors))), self::REFUSED);
        }

        return $data;
    }

    /**
     * A message from Virtualizor, without the API key or password, tags or line breaks.
     */
    private function clean(Server $server, string $message): string
    {
        foreach (array_filter([(string) $server->api_token, (string) $server->password], fn (string $secret): bool => strlen($secret) >= 4) as $secret) {
            $message = str_ireplace([$secret, rawurlencode($secret), urlencode($secret)], '[hidden]', $message);
        }

        $message = (string) preg_replace('/\b(adminapikey|adminapipass|apikey|apipass)=[^&\s]*/i', '$1=[hidden]', $message);

        return Str::limit(trim((string) preg_replace('/\s+/', ' ', strip_tags($message))), 300);
    }
}
