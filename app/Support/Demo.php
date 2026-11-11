<?php

namespace App\Support;

use App\Provisioning\Hardware\BmcException;
use App\Provisioning\Hardware\Tls\CertificateProbe;
use App\Provisioning\Hardware\Tls\PeerCertificate;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The public demo (NUVABILL_DEMO=true): shared sign-ins, fresh data every hour, and no
 * changes that could lock visitors out, send email or reach other servers.
 */
class Demo
{
    public const ADMIN_EMAIL = 'admin@nuvabill.test';

    public const CLIENT_EMAIL = 'client@nuvabill.test';

    public const PASSWORD = 'nuvabill-demo';

    /**
     * The demo's Virtualizor node. It does not exist: fakeServers() answers for it.
     */
    public const VPS_HOST = 'vps.demo.nuvabill.test';

    /**
     * The BMCs of the demo's dedicated servers. They are documentation (TEST-NET) addresses that
     * never route anywhere: fakeServers() answers for every https://192.0.2.* address like a
     * Redfish BMC. The server of the first one is on, the others are off.
     *
     * @var list<string>
     */
    public const BMC_HOSTS = ['192.0.2.21', '192.0.2.22', '192.0.2.23', '192.0.2.24', '192.0.2.25', '192.0.2.26'];

    /**
     * The Redfish path of the one system each demo BMC lists.
     */
    public const BMC_SYSTEM = '/redfish/v1/Systems/1';

    /**
     * Routes visitors can open but not save in the demo.
     *
     * @var list<string>
     */
    public const LOCKED_ROUTES = [
        'admin.profile.*',
        'admin.settings.update',
        'admin.settings.mail',
        'admin.settings.mail.test',
        'admin.settings.icon.store',
        'admin.settings.icon.destroy',
        'admin.settings.gateways.update',
        'admin.settings.currencies.update',
        'admin.settings.taxes.settings',
        'admin.profile.api-keys.store',
        'admin.settings.license.update',
        'admin.settings.license.check',
        'admin.marketplace.connect',
        'admin.settings.seo.update',
        'admin.settings.ai.update',
        'admin.settings.ai.test',
        'admin.settings.chat.telegram',
        'admin.settings.chat.telegram.*',
        'admin.settings.chat.whatsapp.*',
        'admin.settings.chat.messages',
        'admin.settings.autopay.update',
        'admin.invoices.charge',
        'admin.clients.payment-methods.destroy',
        'admin.automations.store',
        'admin.automations.update',
        'admin.automations.destroy',
        'admin.automations.toggle',
        'admin.settings.seo.image.destroy',
        'admin.settings.seo.redirects.destroy',
        'admin.affiliates.settings',
        'admin.settings.taxes.store',
        'admin.settings.taxes.update',
        'admin.settings.taxes.destroy',
        'admin.settings.registrars.*',
        'admin.settings.tlds.*',
        'admin.settings.email-templates.update',
        'admin.settings.departments.*',
        'admin.settings.import.update',
        'admin.settings.social.update',
        'admin.settings.security.update',
        'admin.settings.security.captcha-check',
        'admin.settings.import.preview',
        'admin.extensions.toggle',
        'admin.extensions.move',
        'admin.extensions.destroy',
        'admin.settings.import.start',
        'admin.settings.import.cancel',
        'admin.domains.action',
        'admin.settings.staff.*',
        'admin.settings.roles.*',
        'admin.updates.*',
        'admin.health.*',
        'admin.marketplace.install',
        'admin.marketplace.destroy',
        'admin.marketplace.activate',
        'admin.marketplace.deactivate',
        'admin.marketplace.license',
        'admin.marketplace.settings.update',
        'admin.addons.*',
        // Server module admin pages and staff actions (pages only read in the demo).
        'admin.server-modules.*',
        'admin.services.staff-action',
        'admin.servers.*',
        'admin.services.module',
        'admin.services.change-plan',
        'admin.services.change-plan.destroy',
        'admin.kb.settings',
        'admin.announcements.settings',
        'admin.network.settings',
        'admin.network.servers',
        'admin.network.check',
        'admin.invoices.credit-notes.store',
        'admin.clients.erase',
        'admin.invoices.refund',
        'client.account.*',
        'client.services.login',
        'client.services.panel',
        'client.domains.nameservers',
        'client.invoices.pay',
        // Resource options, the client server panel and dedicated hardware. The two quote routes stay
        // open: they only work out a price.
        'client.services.resources.store',
        'client.services.resources.destroy',
        'client.services.sections.action',
        'client.services.console.open',
        'client.services.console.redeem',
        'admin.products.resources.store',
        'admin.products.resources.update',
        'admin.products.resources.destroy',
        'admin.products.resources.order',
        'admin.products.resources.base',
        'admin.products.resources.old-addons.destroy',
        'admin.services.resources.change',
        'admin.services.resources.cancel',
        'admin.services.resources.apply',
        'admin.services.resources.check',
        'admin.services.resources.retry',
        'admin.services.resources.done',
        'admin.services.access-check',
        'admin.services.link',
        'admin.machines.*',
        'admin.hardware-addons.*',
        'admin.services.machine.*',
    ];

    public static function isEnabled(): bool
    {
        return (bool) config('nuvabill.demo');
    }

    /**
     * The add-ons the demo switches on after every reset (NUVABILL_DEMO_ADDONS), as slugs. Always
     * empty while demo mode is off, so the setting can never switch anything on for a real site.
     *
     * @return list<string>
     */
    public static function addons(): array
    {
        if (! self::isEnabled()) {
            return [];
        }

        $value = config('nuvabill.demo_addons');
        $slugs = is_array($value) ? $value : explode(',', (string) $value);
        $slugs = array_map(fn (mixed $slug): string => is_string($slug) ? strtolower(trim($slug)) : '', $slugs);

        return array_values(array_unique(array_filter($slugs, fn (string $slug): bool => preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $slug) === 1)));
    }

    /**
     * Made-up answers from the demo's servers, so visitors see the server panels working:
     *
     * - the Virtualizor node's Admin API (port 4085) and end-user panel (port 4083), with live
     *   figures, a month of graphs, reverse DNS, an SSH key, a firewall plan, backups, ISO images,
     *   tasks and logs for every demo VPS;
     * - a Redfish BMC on every https://192.0.2.* address (BMC_HOSTS), for the dedicated servers.
     *
     * Panel buttons, consoles and hardware actions stay locked in the demo (LOCKED_ROUTES), so only
     * reads reach this. A change sent to the end-user panel anyway is refused. A BMC certificate is
     * read over its own TLS connection, outside the HTTP client: in the demo that read is refused
     * before it connects.
     */
    public static function fakeServers(): void
    {
        Http::fake([
            'https://'.self::VPS_HOST.':4085/*' => fn (Request $request): PromiseInterface => Http::response(self::adminApi(self::query($request))),
            'https://'.self::VPS_HOST.':4083/*' => fn (Request $request): PromiseInterface => Http::response(
                $request->method() === 'GET' ? self::enduserPanel(self::query($request)) : self::refused(),
            ),
            'https://192.0.2.*' => fn (Request $request): PromiseInterface => self::redfish($request),
        ]);

        app()->bind(CertificateProbe::class, fn (): CertificateProbe => new class implements CertificateProbe
        {
            public function fetch(string $ip, int $port, string $serverName): PeerCertificate
            {
                throw BmcException::unreachable();
            }
        });
    }

    /**
     * False on a new demo site until the first reset has filled the database.
     */
    public static function hasData(): bool
    {
        try {
            return Schema::hasTable('admins');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function query(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * The demo node's Admin API: OS templates, a Virtualizor user, the live figures of a VPS, or
     * the VPS itself for any other call. Each demo VPS has its own Virtualizor user with the same
     * number.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private static function adminApi(array $query): array
    {
        // The status call names the VPS as vs_status=101 (older versions sent a list).
        $status = $query['vs_status'] ?? null;
        $vpsId = self::vpsId($query['vpsid'] ?? (is_array($status) ? (reset($status) ?: null) : $status));
        $seed = crc32($vpsId);

        return match (true) {
            ($query['act'] ?? '') === 'ostemplates' => ['ostemplates' => [
                '100' => ['osid' => 100, 'type' => 'kvm', 'name' => 'Ubuntu 24.04'],
                '101' => ['osid' => 101, 'type' => 'kvm', 'name' => 'Debian 12'],
                '102' => ['osid' => 102, 'type' => 'kvm', 'name' => 'AlmaLinux 9'],
                '103' => ['osid' => 103, 'type' => 'kvm', 'name' => 'Windows Server 2022'],
            ]],
            ($query['act'] ?? '') === 'users' => ['users' => [self::vpsId($query['uid'] ?? null) => ['uid' => self::vpsId($query['uid'] ?? null), 'type' => '0']]],
            isset($query['vs_status']) => ['status' => [$vpsId => [
                'status' => 1,
                'used_cpu' => 4 + $seed % 30 + (int) date('s') / 10,
                'used_ram' => 900 + $seed % 1800,
                'ram' => 4096,
                'used_disk' => 12 + $seed % 40,
                'disk' => 80,
                'used_bandwidth' => 120 + $seed % 900,
                'bandwidth' => 2048,
            ]]],
            default => ['vs' => [$vpsId => [
                'vpsid' => $vpsId,
                'uid' => $vpsId,
                'hostname' => 'vps'.$vpsId.'.yourhost.net',
                'os_name' => 'Ubuntu 24.04',
                'virt' => 'kvm',
                'suspended' => '0',
                'cores' => '2',
                'ram' => '4096',
                'space' => '80',
                'bandwidth' => '2048',
                'ips' => ['1' => self::vpsIp($vpsId)],
                'ips6' => ['2' => self::vpsIpv6($vpsId).'2'],
                'ips6_subnet' => ['3' => self::vpsIpv6($vpsId).'/64'],
            ]]],
        };
    }

    /**
     * The demo node's end-user panel: every read the client server panel makes, for the VPS the
     * call is pinned to (svs). Any other act is refused.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private static function enduserPanel(array $query): array
    {
        $vpsId = self::vpsId($query['svs'] ?? null);
        $now = CarbonImmutable::instance(Date::now());
        $show = is_string($query['show'] ?? null) ? $query['show'] : '';

        return match ($query['act'] ?? null) {
            'vpsmanage' => ['title' => 'VPS Management', 'info' => [
                'status' => 1,
                'virt' => 'kvm',
                'hostname' => 'vps'.$vpsId.'.yourhost.net',
                'os' => ['name' => 'Ubuntu 24.04'],
                'vps' => ['vpsid' => $vpsId, 'vnc' => '1', 'suspended' => '0', 'rescue' => '0', 'admin_managed' => '0'],
                'flags' => ['enable_rdns' => 1, 'rescue_mode' => 1, 'bpid' => 1, 'hvmsettings' => 1],
            ]],
            'monitor' => self::monitor($vpsId, $show, $now),
            'bandwidth' => self::bandwidth($vpsId, $show, $now),
            'ctasks' => ['tasks' => [
                '9021' => ['actid' => '9021', 'action_txt' => 'Restart VPS', 'status_txt' => 'Task completed', 'progress' => 100, 'started' => $now->subHours(2)->getTimestamp(), 'updated' => $now->subHours(2)->addSeconds(40)->getTimestamp(), 'ended' => $now->subHours(2)->addSeconds(40)->getTimestamp()],
                '8977' => ['actid' => '8977', 'action_txt' => 'Change hostname', 'status_txt' => 'Task completed', 'progress' => 100, 'started' => $now->subDays(3)->getTimestamp(), 'updated' => $now->subDays(3)->addSeconds(5)->getTimestamp(), 'ended' => $now->subDays(3)->addSeconds(5)->getTimestamp()],
            ]],
            'logs' => ['logs' => [
                ['time' => $now->subHours(2)->getTimestamp(), 'action_text' => 'Restarted the VPS', 'status' => 'Done'],
                ['time' => $now->subDays(3)->getTimestamp(), 'action_text' => 'Changed the hostname', 'status' => 'Done'],
            ]],
            'rdns' => ['rdns' => ['1' => ['id' => 1, 'ip' => self::vpsIp($vpsId), 'content' => 'vps'.$vpsId.'.yourhost.net']]],
            'sshkeys' => ['ssh_keys' => ['3' => ['keyid' => 3, 'name' => 'Raz laptop']]],
            'firewallplan' => ['current_firewall_plan' => '4', 'firewall_plans' => ['4' => [
                'fwid' => 4,
                'fwp_name' => 'Web server',
                'default_policy' => 'drop',
                'uid' => $vpsId,
                'rules' => [
                    ['direction' => 'in', 'protocol' => 'tcp', 'port' => '22', 'action' => 'accept'],
                    ['direction' => 'in', 'protocol' => 'tcp', 'port' => '80', 'action' => 'accept'],
                    ['direction' => 'in', 'protocol' => 'tcp', 'port' => '443', 'action' => 'accept'],
                    ['direction' => 'in', 'protocol' => 'icmp', 'action' => 'accept'],
                ],
            ]]],
            'backup2' => ['backup_file_list' => [
                'b1' => ['name' => $now->subDay()->format('Y-m-d').'_v'.$vpsId.'.tar.gz', 'time' => $now->subDay()->setTime(3, 0)->getTimestamp(), 'size' => 1_932_735_283],
                'b2' => ['name' => $now->subDays(8)->format('Y-m-d').'_v'.$vpsId.'.tar.gz', 'time' => $now->subDays(8)->setTime(3, 0)->getTimestamp(), 'size' => 1_825_361_100],
            ], 'backup_limit' => 3, 'restores_used' => 0, 'restore_limit' => 2],
            'rescue' => ['rescue_enabled' => 0],
            'hvmsettings' => [
                'vps' => ['vpsid' => $vpsId, 'acpi' => '1', 'apic' => '1', 'vnc' => '1', 'vnc_keymap' => 'en-us', 'kvm_vga' => '1', 'acceleration' => '1', 'nic_type' => 'virtio', 'virtio' => '1', 'timezone' => 'UTC', 'iso' => '0', 'boot' => 'cda'],
                'isos' => [
                    '11' => ['isoid' => 11, 'name' => 'ubuntu-24.04-live-server-amd64.iso'],
                    '12' => ['isoid' => 12, 'name' => 'debian-12-amd64-netinst.iso'],
                ],
            ],
            'listvs' => ['vs' => [$vpsId => ['vpsid' => $vpsId, 'uid' => $vpsId]]],
            default => self::refused(),
        };
    }

    /**
     * Hourly figures for one month (up to now in the current month) that rise in the day and fall
     * at night, the same for a VPS on every visit. The VPS was made five months ago.
     *
     * @return array{monthly_data: array<string, array<string, float|int>>, vps_create_date: int}
     */
    private static function monitor(string $vpsId, string $show, CarbonImmutable $now): array
    {
        [$start, $end] = self::month($show, $now);
        $seed = crc32($vpsId);
        $rows = [];

        for ($at = $start; $at->lte($end); $at = $at->addHour()) {
            $time = $at->getTimestamp();
            $busy = max(0.0, sin(((int) $at->format('G') - 6) / 12 * M_PI));
            $noise = crc32($vpsId.':'.$time) % 1000 / 1000;

            $rows[(string) $time] = [
                'cpu' => round(3 + $seed % 8 + 22 * $busy + 6 * $noise, 2),
                'ram' => 900 + $seed % 700 + (int) (500 * $busy + 120 * $noise),
                'disk' => round(14 + $seed % 30 + ($time - $start->getTimestamp()) / 86400 * 0.08, 2),
                'net_in' => (int) (30000 + 250000 * $busy + 40000 * $noise),
                'net_out' => (int) (15000 + 120000 * $busy + 25000 * $noise),
                'io_read' => (int) (20000 + 180000 * $busy * $noise),
                'io_write' => (int) (40000 + 90000 * $busy + 30000 * $noise),
            ];
        }

        return ['monthly_data' => $rows, 'vps_create_date' => $now->startOfMonth()->subMonths(5)->addDays(3)->getTimestamp()];
    }

    /**
     * The traffic of each day of one month in MB, and the month's use against a 2 TB limit.
     *
     * @return array{bandwidth: array{limit_gb: int, used_gb: float, in: array<int, float>, out: array<int, float>}}
     */
    private static function bandwidth(string $vpsId, string $show, CarbonImmutable $now): array
    {
        [$start, $end] = self::month($show, $now);
        $in = [];
        $out = [];

        for ($day = 1; $day <= (int) $end->format('j'); $day++) {
            $noise = crc32($vpsId.':'.$start->format('Ym').':'.$day) % 1000 / 1000;
            $in[$day] = round(1500 + crc32($vpsId) % 2500 + 2000 * $noise, 1);
            $out[$day] = round($in[$day] * (0.35 + 0.3 * $noise), 1);
        }

        return ['bandwidth' => ['limit_gb' => 2048, 'used_gb' => round((array_sum($in) + array_sum($out)) / 1024, 2), 'in' => $in, 'out' => $out]];
    }

    /**
     * The first hour of the month asked for (Ym, the current month when it is missing, invalid or
     * still to come) and its last hour so far.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function month(string $show, CarbonImmutable $now): array
    {
        $start = preg_match('/^\d{4}(0[1-9]|1[0-2])\z/', $show) === 1 ? CarbonImmutable::createFromFormat('!Ym', $show, $now->getTimezone()) : null;
        $start = $start instanceof CarbonImmutable && $start->lte($now) ? $start : $now->startOfMonth();

        return [$start, $start->endOfMonth()->min($now)];
    }

    /**
     * A Redfish BMC with one system: the service root, the systems, the system with its power state,
     * reset types and health, its chassis with temperatures, fans, power supplies and the watts it
     * uses now, and its two drives. The reset action and the next boot device are accepted and
     * change nothing. Temperatures and watts rise in the day and fall at night, the same for a BMC
     * at the same time, so the Dedicated Server Power add-on has health checks and power use to
     * show when the demo switches it on (NUVABILL_DEMO_ADDONS).
     */
    private static function redfish(Request $request): PromiseInterface
    {
        $host = (string) parse_url($request->url(), PHP_URL_HOST);
        $path = rtrim((string) parse_url($request->url(), PHP_URL_PATH), '/');
        $missing = ['error' => ['code' => 'Base.1.8.ResourceMissingAtURI', 'message' => 'The resource was not found.']];

        if ($request->method() === 'POST') {
            return $path === self::BMC_SYSTEM.'/Actions/ComputerSystem.Reset' ? Http::response(null, 204) : Http::response($missing, 404);
        }

        if ($request->method() === 'PATCH') {
            return $path === self::BMC_SYSTEM ? Http::response(null, 204) : Http::response($missing, 404);
        }

        $on = $host === self::BMC_HOSTS[0];
        $chassis = '/redfish/v1/Chassis/1';
        $storage = self::BMC_SYSTEM.'/Storage';
        $ok = ['State' => 'Enabled', 'Health' => 'OK'];
        $rollup = $ok + ['HealthRollup' => 'OK'];

        $now = Date::now();
        $busy = $on ? max(0.0, sin(((int) $now->format('G') - 6) / 12 * M_PI)) : 0.0;
        $noise = crc32($host.':'.intdiv($now->getTimestamp(), 300)) % 1000 / 1000;
        $fan = $on ? 4200 + 2400 * $busy + 300 * $noise : 1800 + 100 * $noise;
        $drive = fn (int $number): array => [
            '@odata.id' => $storage.'/1/Drives/'.$number,
            'Id' => (string) $number,
            'Name' => '960 GB NVMe '.($number + 1),
            'CapacityBytes' => 960_197_124_096,
            'Protocol' => 'NVMe',
            'MediaType' => 'SSD',
            'FailurePredicted' => false,
            'Status' => $ok,
        ];

        return match ($path) {
            '/redfish/v1' => Http::response(['@odata.id' => '/redfish/v1/', 'Name' => 'Root Service', 'RedfishVersion' => '1.11.0', 'Systems' => ['@odata.id' => '/redfish/v1/Systems']]),
            '/redfish/v1/Systems' => Http::response(['@odata.id' => '/redfish/v1/Systems', 'Members' => [['@odata.id' => self::BMC_SYSTEM]], 'Members@odata.count' => 1]),
            self::BMC_SYSTEM => Http::response([
                '@odata.id' => self::BMC_SYSTEM,
                'Id' => '1',
                'Manufacturer' => 'Supermicro',
                'Model' => 'SYS-510T-MR',
                'PowerState' => $on ? 'On' : 'Off',
                'Status' => $rollup,
                'ProcessorSummary' => ['Count' => 1, 'Model' => 'Intel Xeon E-2388G', 'Status' => $rollup],
                'MemorySummary' => ['TotalSystemMemoryGiB' => 64, 'Status' => $rollup],
                'Storage' => ['@odata.id' => $storage],
                'Links' => ['Chassis' => [['@odata.id' => $chassis]]],
                'Actions' => ['#ComputerSystem.Reset' => [
                    'target' => self::BMC_SYSTEM.'/Actions/ComputerSystem.Reset',
                    'ResetType@Redfish.AllowableValues' => ['On', 'ForceOff', 'GracefulShutdown', 'GracefulRestart', 'ForceRestart'],
                ]],
            ]),
            $chassis => Http::response(['@odata.id' => $chassis, 'Id' => '1', 'Name' => 'Chassis', 'Status' => $ok, 'Thermal' => ['@odata.id' => $chassis.'/Thermal'], 'Power' => ['@odata.id' => $chassis.'/Power']]),
            $chassis.'/Thermal' => Http::response(['@odata.id' => $chassis.'/Thermal', 'Temperatures' => [
                ['MemberId' => '0', 'Name' => 'CPU Temp', 'ReadingCelsius' => (int) round($on ? 41 + 14 * $busy + 3 * $noise : 24 + 2 * $noise), 'UpperThresholdNonCritical' => 85, 'UpperThresholdCritical' => 95, 'Status' => $ok],
                ['MemberId' => '1', 'Name' => 'System Temp', 'ReadingCelsius' => (int) round(22 + 6 * $busy + $noise), 'UpperThresholdNonCritical' => 75, 'UpperThresholdCritical' => 85, 'Status' => $ok],
            ], 'Fans' => [
                ['MemberId' => '0', 'Name' => 'FAN1', 'Reading' => (int) round($fan, -2), 'ReadingUnits' => 'RPM', 'LowerThresholdCritical' => 420, 'Status' => $ok],
                ['MemberId' => '1', 'Name' => 'FAN2', 'Reading' => (int) round($fan * 0.97, -2), 'ReadingUnits' => 'RPM', 'LowerThresholdCritical' => 420, 'Status' => $ok],
            ]]),
            $chassis.'/Power' => Http::response(['@odata.id' => $chassis.'/Power', 'PowerControl' => [
                ['MemberId' => '0', 'Name' => 'System Power Control', 'PowerConsumedWatts' => (int) round($on ? 92 + 48 * $busy + 9 * $noise : 9 + $noise)],
            ], 'PowerSupplies' => [
                ['MemberId' => '0', 'Name' => 'PSU1', 'PowerCapacityWatts' => 500, 'Status' => $ok],
                ['MemberId' => '1', 'Name' => 'PSU2', 'PowerCapacityWatts' => 500, 'Status' => $ok],
            ]]),
            $storage => Http::response(['@odata.id' => $storage, 'Members' => [['@odata.id' => $storage.'/1']], 'Members@odata.count' => 1]),
            $storage.'/1' => Http::response(['@odata.id' => $storage.'/1', 'Id' => '1', 'Name' => 'NVMe', 'Drives' => [['@odata.id' => $storage.'/1/Drives/0'], ['@odata.id' => $storage.'/1/Drives/1']]]),
            $storage.'/1/Drives/0' => Http::response($drive(0)),
            $storage.'/1/Drives/1' => Http::response($drive(1)),
            default => Http::response($missing, 404),
        };
    }

    /**
     * Virtualizor's answer to a change in the demo.
     *
     * @return array{error: list<string>}
     */
    private static function refused(): array
    {
        return ['error' => ['This is turned off in the demo.']];
    }

    /**
     * A VPS or user number from a request, or 101 when it is not one.
     */
    private static function vpsId(mixed $value): string
    {
        return is_scalar($value) && preg_match('/^[1-9]\d{0,9}\z/', (string) $value) === 1 ? (string) $value : '101';
    }

    private static function vpsIp(string $vpsId): string
    {
        return '203.0.113.'.(10 + crc32($vpsId) % 200);
    }

    /**
     * The start of the VPS's IPv6 network, such as "2001:db8:65::" for VPS 101.
     */
    private static function vpsIpv6(string $vpsId): string
    {
        return '2001:db8:'.dechex((int) $vpsId % 65536).'::';
    }
}
