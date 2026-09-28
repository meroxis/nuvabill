<?php

namespace Tests\Fixtures\Servers;

use App\Contracts\HasLoginForm;
use App\Extensions\Servers\Module;
use App\Extensions\Servers\ModuleResult;
use App\Models\Server;
use App\Models\Service;

class FormPanelModule extends Module implements HasLoginForm
{
    public function defaultPort(): int
    {
        return 8090;
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

    public function loginForm(Service $service): ?array
    {
        return [
            'url' => 'https://'.$service->server->hostname.':8090/login',
            'fields' => ['username' => (string) $service->username, 'password' => (string) $service->password],
        ];
    }
}
