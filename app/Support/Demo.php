<?php

namespace App\Support;

use Illuminate\Http\Client\Request;
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
     * Routes visitors can open but not save in the demo.
     *
     * @var list<string>
     */
    public const LOCKED_ROUTES = [
        'admin.profile.*',
        'admin.settings.update',
        'admin.settings.mail',
        'admin.settings.mail.test',
        'admin.settings.gateways.update',
        'admin.settings.registrars.*',
        'admin.settings.import.update',
        'admin.settings.social.update',
        'admin.settings.import.start',
        'admin.settings.import.cancel',
        'admin.domains.action',
        'admin.settings.staff.*',
        'admin.settings.roles.*',
        'admin.updates.*',
        'admin.servers.*',
        'admin.services.module',
        'client.account.*',
        'client.services.login',
        'client.services.panel',
        'client.domains.nameservers',
        'client.invoices.pay',
    ];

    public static function isEnabled(): bool
    {
        return (bool) config('nuvabill.demo');
    }

    /**
     * Made-up answers from the demo Virtualizor node, so visitors see the VPS panel working.
     * Panel buttons stay locked in the demo, so only reads reach this.
     */
    public static function fakeServers(): void
    {
        Http::fake(['https://'.self::VPS_HOST.':4085/*' => function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $vpsId = (string) ($query['vpsid'] ?? $query['vs_status'][0] ?? '101');
            $seed = crc32($vpsId);

            return match (true) {
                ($query['act'] ?? '') === 'ostemplates' => Http::response(['ostemplates' => [
                    '100' => ['osid' => 100, 'type' => 'kvm', 'name' => 'Ubuntu 24.04'],
                    '101' => ['osid' => 101, 'type' => 'kvm', 'name' => 'Debian 12'],
                    '102' => ['osid' => 102, 'type' => 'kvm', 'name' => 'AlmaLinux 9'],
                    '103' => ['osid' => 103, 'type' => 'kvm', 'name' => 'Windows Server 2022'],
                ]]),
                isset($query['vs_status']) => Http::response(['status' => [$vpsId => [
                    'status' => 1,
                    'used_cpu' => 4 + $seed % 30 + (int) date('s') / 10,
                    'used_ram' => 900 + $seed % 1800,
                    'ram' => 4096,
                    'used_disk' => 12 + $seed % 40,
                    'disk' => 80,
                    'used_bandwidth' => 120 + $seed % 900,
                    'bandwidth' => 2048,
                ]]]),
                default => Http::response(['vs' => [$vpsId => [
                    'hostname' => 'vps'.$vpsId.'.yourhost.net',
                    'os_name' => 'Ubuntu 24.04',
                    'virt' => 'kvm',
                    'ips' => ['203.0.113.'.(10 + $seed % 200)],
                ]]]),
            };
        }]);
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
}
