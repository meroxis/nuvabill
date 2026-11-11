<?php

namespace Tests\Fixtures\OldPanel;

use App\Contracts\HasClientPanel;
use App\Extensions\Servers\Module;
use App\Extensions\Servers\ModuleResult;
use App\Models\Server;
use App\Models\Service;

/**
 * A server module for tests only, with the older client panel: the shared VPS panel view and
 * actions that answer with a ModuleResult. It makes no network calls.
 *
 * - "restart" leaves a pending power action, next to a value core must not keep.
 * - "vnc" answers the way module versions from before ModuleResult::secret() did: data['vnc'].
 * - "keys" answers with ModuleResult::secret().
 */
class OldPanelModule extends Module implements HasClientPanel
{
    public const SECRET = 'Once-Only-9431';

    public function defaultPort(): int
    {
        return 8443;
    }

    public function testConnection(Server $server): ModuleResult
    {
        return ModuleResult::ok();
    }

    public function create(Service $service): ModuleResult
    {
        return ModuleResult::ok();
    }

    public function suspend(Service $service, string $reason): ModuleResult
    {
        return ModuleResult::ok();
    }

    public function unsuspend(Service $service): ModuleResult
    {
        return ModuleResult::ok();
    }

    public function terminate(Service $service): ModuleResult
    {
        return ModuleResult::ok();
    }

    public function clientPanelView(): string
    {
        return 'theme::client.services.vps-panel';
    }

    public function clientPanel(Service $service): array
    {
        return ['hostname' => 'vps.example.test', 'state' => 'running', 'ips' => ['203.0.113.20']];
    }

    public function clientActions(): array
    {
        return ['restart' => 'Restart', 'vnc' => 'Show VNC details', 'keys' => 'Show the keys'];
    }

    public function clientAction(Service $service, string $action, array $input): ModuleResult
    {
        return match ($action) {
            'restart' => ModuleResult::ok('The server is restarting.', ['pending' => 'restart', 'internal' => self::SECRET]),
            'vnc' => ModuleResult::ok('VNC details are shown below.', ['vnc' => ['ip' => '203.0.113.9', 'port' => 5901, 'password' => self::SECRET]]),
            'keys' => ModuleResult::secret('The keys are shown below.', ['Access key' => self::SECRET, 'Empty' => '']),
            default => ModuleResult::fail('This action is not available.'),
        };
    }
}
