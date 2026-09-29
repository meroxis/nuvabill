<?php

namespace App\Provisioning;

use App\Contracts\HasClientPanel;
use App\Contracts\HasLoginForm;
use App\Contracts\ServerModule;
use App\Enums\ServiceStatus;
use App\Events\ServiceActivated;
use App\Events\ServiceSuspended;
use App\Events\ServiceTerminated;
use App\Extensions\ExtensionManager;
use App\Extensions\Servers\Module;
use App\Extensions\Servers\ModuleResult;
use App\Mail\TemplateMailer;
use App\Models\Server;
use App\Models\Service;
use App\Support\Activity;
use App\Support\Locales;
use Closure;
use Throwable;

/**
 * Runs control panel actions for a service and keeps its status, log and emails in step.
 * Products without a server module are handled here too: their status simply changes.
 */
class Provisioner
{
    public function __construct(
        private ExtensionManager $extensions,
        private TemplateMailer $mailer,
    ) {}

    public function create(Service $service): ModuleResult
    {
        $service->loadMissing('product', 'client', 'server');

        if ($service->status === ServiceStatus::Active) {
            return ModuleResult::ok(__('The service is already active.'));
        }

        $module = $this->moduleFor($service);

        if ($module instanceof ModuleResult) {
            return $this->failed($service, 'create', $module);
        }

        if ($module !== null && $service->server === null) {
            $server = $this->pickServer($service);

            if ($server === null) {
                return $this->failed($service, 'create', ModuleResult::fail(__('No active :module server has free space.', ['module' => $module->name()])));
            }

            $service->server()->associate($server);
            $service->save();
        }

        $result = $module === null ? ModuleResult::ok(__('Activated.')) : $this->attempt(fn (): ModuleResult => $module->create($service));

        if (! $result->success) {
            return $this->failed($service, 'create', $result);
        }

        $service->fill(array_intersect_key($result->data, array_flip(['username', 'password'])));

        if (is_array($result->data['module_data'] ?? null)) {
            $service->module_data = array_merge((array) $service->module_data, $result->data['module_data']);
        }

        $service->status = ServiceStatus::Active;
        $service->save();

        Activity::log('service.created', "Service #{$service->id} ({$service->label()}) set up: {$result->message}", $service);
        $this->mailer->send('service.welcome', $service->client, TemplateMailer::serviceContext($service));

        ServiceActivated::dispatch($service);

        return $result;
    }

    public function suspend(Service $service, string $reason): ModuleResult
    {
        $service->loadMissing('product', 'client', 'server');

        if ($service->status === ServiceStatus::Suspended) {
            return ModuleResult::ok(__('The service is already suspended.'));
        }

        $result = $this->runModule($service, fn (ServerModule $module): ModuleResult => $module->suspend($service, $reason));

        if (! $result->success) {
            return $this->failed($service, 'suspend', $result);
        }

        $service->update([
            'status' => ServiceStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ]);

        Activity::log('service.suspended', "Service #{$service->id} ({$service->label()}) suspended: {$reason}", $service);
        $this->mailer->send('service.suspended', $service->client, TemplateMailer::serviceContext($service) + ['reason' => Locales::in(Locales::forClient($service->client), fn (): string => __($reason))]);
        ServiceSuspended::dispatch($service);

        return $result;
    }

    public function unsuspend(Service $service): ModuleResult
    {
        $service->loadMissing('product', 'client', 'server');

        if ($service->status !== ServiceStatus::Suspended) {
            return ModuleResult::ok(__('The service is not suspended.'));
        }

        $result = $this->runModule($service, fn (ServerModule $module): ModuleResult => $module->unsuspend($service));

        if (! $result->success) {
            return $this->failed($service, 'unsuspend', $result);
        }

        $service->update([
            'status' => ServiceStatus::Active,
            'suspended_at' => null,
            'suspension_reason' => null,
        ]);

        Activity::log('service.unsuspended', "Service #{$service->id} ({$service->label()}) unsuspended", $service);
        $this->mailer->send('service.unsuspended', $service->client, TemplateMailer::serviceContext($service));

        return $result;
    }

    public function terminate(Service $service): ModuleResult
    {
        $service->loadMissing('product', 'client', 'server');

        if ($service->status === ServiceStatus::Terminated) {
            return ModuleResult::ok(__('The service is already terminated.'));
        }

        $wasProvisioned = in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true);

        $result = $wasProvisioned
            ? $this->runModule($service, fn (ServerModule $module): ModuleResult => $module->terminate($service))
            : ModuleResult::ok(__('Nothing to remove on the server.'));

        if (! $result->success) {
            return $this->failed($service, 'terminate', $result);
        }

        $service->update([
            'status' => ServiceStatus::Terminated,
            'terminated_at' => now(),
            'next_due_date' => null,
        ]);

        Activity::log('service.terminated', "Service #{$service->id} ({$service->label()}) terminated", $service);
        ServiceTerminated::dispatch($service);

        return $result;
    }

    public function changePackage(Service $service): ModuleResult
    {
        $service->loadMissing('product', 'client', 'server');

        $result = $this->runModule($service, fn (ServerModule $module): ModuleResult => $module->changePackage($service));

        if (! $result->success) {
            return $this->failed($service, 'change package', $result);
        }

        Activity::log('service.package_changed', "Service #{$service->id} ({$service->label()}) package updated on the server", $service);

        return $result;
    }

    public function loginUrl(Service $service): ?string
    {
        $service->loadMissing('product', 'server');

        $module = $this->moduleFor($service);

        if (! $module instanceof ServerModule || $service->status !== ServiceStatus::Active) {
            return null;
        }

        try {
            return $module->loginUrl($service);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * The module's own client panel for an active service, as [view, data], or null when it has none.
     * A control panel that does not answer gives the panel an error message instead of breaking the page.
     *
     * @return array{view: string, data: array<string, mixed>, actions: array<string, string>}|null
     */
    public function clientPanel(Service $service): ?array
    {
        $service->loadMissing('product', 'server');
        $module = $this->moduleFor($service);

        if (! $module instanceof HasClientPanel || $service->status !== ServiceStatus::Active || $service->server === null) {
            return null;
        }

        try {
            $data = $module->clientPanel($service);
        } catch (Throwable $exception) {
            report($exception);
            $data = ['error' => __('The server did not answer. Please try again in a minute.')];
        }

        return ['view' => $module->clientPanelView(), 'data' => $data, 'actions' => $module->clientActions()];
    }

    /**
     * Run a client panel action (for example "restart") and log it.
     *
     * @param  array<string, mixed>  $input
     */
    public function clientAction(Service $service, string $action, array $input): ModuleResult
    {
        $service->loadMissing('product', 'server', 'client');
        $module = $this->moduleFor($service);

        if (! $module instanceof HasClientPanel || ! array_key_exists($action, $module->clientActions()) || $service->status !== ServiceStatus::Active) {
            return ModuleResult::fail(__('This action is not available.'));
        }

        $result = $this->attempt(fn (): ModuleResult => $module->clientAction($service, $action, $input));

        Activity::log(
            $result->success ? 'service.client_action' : 'service.module_failed',
            "Client action \"{$action}\" on service #{$service->id} ({$service->label()}): {$result->message}",
            $service,
        );

        return $result;
    }

    /**
     * The sign-in form of a module that signs clients in with a form, for an active service.
     *
     * @return array{url: string, fields: array<string, string>}|null
     */
    public function loginForm(Service $service): ?array
    {
        $service->loadMissing('product', 'server');
        $module = $this->moduleFor($service);

        if (! $module instanceof HasLoginForm || $service->status !== ServiceStatus::Active || $service->server === null) {
            return null;
        }

        try {
            $form = $module->loginForm($service);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return $form !== null && str_starts_with((string) ($form['url'] ?? ''), 'https://') ? $form : null;
    }

    public function usesLoginForm(Service $service): bool
    {
        return $this->moduleFor($service->loadMissing('product')) instanceof HasLoginForm;
    }

    public function hasLoginLink(Service $service): bool
    {
        $module = $this->moduleFor($service->loadMissing('product'));

        return $module instanceof Module ? $module->hasLoginLink() : $module instanceof ServerModule;
    }

    public function testConnection(Server $server): ModuleResult
    {
        try {
            $module = $this->extensions->serverModule($server->module);
        } catch (Throwable $exception) {
            return ModuleResult::fail($exception->getMessage());
        }

        return $this->attempt(fn (): ModuleResult => $module->testConnection($server));
    }

    /**
     * The service's module, null when the product has none, or a failed result when it is missing.
     */
    private function moduleFor(Service $service): ServerModule|ModuleResult|null
    {
        $slug = $service->product->server_module;

        if (blank($slug)) {
            return null;
        }

        try {
            return $this->extensions->serverModule($slug);
        } catch (Throwable) {
            return ModuleResult::fail(__('The server module ":module" is not installed.', ['module' => $slug]));
        }
    }

    /**
     * @param  Closure(ServerModule): ModuleResult  $action
     */
    private function runModule(Service $service, Closure $action): ModuleResult
    {
        $module = $this->moduleFor($service);

        return match (true) {
            $module === null => ModuleResult::ok(__('No server module; status updated only.')),
            $module instanceof ModuleResult => $module,
            default => $this->attempt(fn (): ModuleResult => $action($module)),
        };
    }

    /**
     * @param  Closure(): ModuleResult  $callback
     */
    private function attempt(Closure $callback): ModuleResult
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            report($exception);

            return ModuleResult::fail($exception->getMessage());
        }
    }

    private function failed(Service $service, string $action, ModuleResult $result): ModuleResult
    {
        Activity::log('service.module_failed', "Could not {$action} service #{$service->id} ({$service->label()}): {$result->message}", $service);

        return $result;
    }

    private function pickServer(Service $service): ?Server
    {
        $product = $service->product;

        if ($product->server !== null && $product->server->is_active && $product->server->hasCapacity()) {
            return $product->server;
        }

        return Server::query()
            ->where('module', $product->server_module)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Server $server): bool => $server->hasCapacity())
            ->sortBy(fn (Server $server): int => $server->accountsCount())
            ->first();
    }
}
