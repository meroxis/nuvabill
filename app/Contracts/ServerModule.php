<?php

namespace App\Contracts;

use App\Extensions\Servers\ModuleResult;
use App\Models\Server;
use App\Models\Service;

/**
 * Talks to a control panel (cPanel/WHM, DirectAdmin, ...) to set up and manage client accounts.
 */
interface ServerModule
{
    public function slug(): string;

    public function name(): string;

    /**
     * Settings each product needs, for example the hosting package name.
     *
     * @return array<string, array{label: string, type: string, help?: string, required?: bool, options?: array<string, string>}>
     */
    public function productFields(): array;

    /**
     * Help text shown on the server form, explaining which credentials to enter.
     */
    public function serverHelp(): string;

    public function defaultPort(): int;

    public function testConnection(Server $server): ModuleResult;

    public function create(Service $service): ModuleResult;

    public function suspend(Service $service, string $reason): ModuleResult;

    public function unsuspend(Service $service): ModuleResult;

    public function terminate(Service $service): ModuleResult;

    public function changePackage(Service $service): ModuleResult;

    /**
     * A one-time link that signs the client in to their control panel, if the panel supports it.
     */
    public function loginUrl(Service $service): ?string;
}
