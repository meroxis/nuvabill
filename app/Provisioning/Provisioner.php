<?php

namespace App\Provisioning;

use App\Billing\ResourceKinds;
use App\Contracts\ChecksClientAccess;
use App\Contracts\ChoosesWelcomeEmail;
use App\Contracts\FinishesSetupLater;
use App\Contracts\HasClientPanel;
use App\Contracts\HasLoginForm;
use App\Contracts\HasPanelSections;
use App\Contracts\HasPanelState;
use App\Contracts\HasResourceOptions;
use App\Contracts\KeepsCreateServer;
use App\Contracts\LinksExistingAccount;
use App\Contracts\QueuesPackageChanges;
use App\Contracts\ServerModule;
use App\Contracts\SignsInWithContext;
use App\Contracts\UsesMachineInventory;
use App\Contracts\WaitsForPayment;
use App\Enums\ServiceStatus;
use App\Events\ServiceActivated;
use App\Events\ServiceSuspended;
use App\Events\ServiceTerminated;
use App\Extensions\ExtensionManager;
use App\Extensions\Servers\Module;
use App\Extensions\Servers\ModuleResult;
use App\Extensions\Servers\Panel\ClientContext;
use App\Extensions\Servers\Panel\PanelResult;
use App\Extensions\Servers\Panel\StandardSections;
use App\Extensions\Servers\Resources\ResourceCheck;
use App\Extensions\Servers\Resources\ResourceExtras;
use App\Extensions\Servers\Resources\ResourceKind;
use App\Extensions\Servers\Resources\ResourceLimit;
use App\Jobs\ChangeServicePackage;
use App\Jobs\UnsuspendService;
use App\Mail\TemplateMailer;
use App\Models\EmailTemplate;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Support\Activity;
use App\Support\Locales;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Runs control panel actions for a service and keeps its status, log and emails in step.
 * Products without a server module are handled here too: their status simply changes.
 *
 * Work that changes a service on its server holds one cache lock per service (lockFor): create
 * and terminate (900 seconds), resource changes (900), plan changes (900; the queued job waits up
 * to 60), suspend (120, waiting up to 30), unsuspend (120), the nightly suspension recheck (120)
 * and the client panel's actions (120). So none of them runs on top of another. Work that must
 * still happen when the service is busy goes to a queued job instead of waiting: an unsuspend
 * (UnsuspendService) and a plan change (ChangeServicePackage).
 */
class Provisioner
{
    /**
     * Cache lock name, followed by the service ID.
     */
    public const LOCK_PREFIX = 'nuvabill:provision:service:';

    /**
     * Module data key: run the module's suspend once more for this suspended service. The value is
     * how many nightly runs may still try. Set for Proxmox VPSs suspended before suspending also
     * turned off start on boot and waited for the stop.
     */
    public const RECHECK_SUSPENSION = 'recheck_suspension';

    /**
     * Seconds the live resource limits of a service are kept.
     */
    private const LIMITS_SECONDS = 60;

    public function __construct(
        private ExtensionManager $extensions,
        private TemplateMailer $mailer,
    ) {}

    /**
     * The one lock for work that changes a service on its server. Get it with get() (give up when
     * busy) or block() (wait a little), and always release it.
     *
     * @param  int  $seconds  How long the lock lasts if it is never released: longer than the slowest module call.
     */
    public function lockFor(Service $service, int $seconds = 900): Lock
    {
        return Cache::lock(self::LOCK_PREFIX.$service->id, $seconds);
    }

    /**
     * Whether the product's server module implements the contract, such as HasResourceOptions.
     * False for a product without a module or with a module that is not installed. Never throws.
     *
     * @param  class-string  $contract
     */
    public function productSupports(Product $product, string $contract): bool
    {
        if (blank($product->server_module)) {
            return false;
        }

        try {
            return $this->extensions->serverModule((string) $product->server_module) instanceof $contract;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Set the service up on its server. One setup runs per service at a time: the queued job, an
     * order accept and a double click on "Create account" would otherwise each make an account.
     */
    public function create(Service $service): ModuleResult
    {
        $lock = $this->lockFor($service);

        if (! $lock->get()) {
            return ModuleResult::fail(__('This service is being set up right now. Check again in a few minutes.'));
        }

        try {
            // Read again inside the lock: the copy given may be older than a setup that just finished.
            $service->refresh();

            [$result, $created] = $this->runCreate($service);
        } finally {
            $lock->release();
        }

        // After the lock is free, so an automation started by the event can act on the service.
        if ($created) {
            [$key, $context] = $this->welcomeEmail($service);
            $this->mailer->send($key, $service->client, TemplateMailer::serviceContext($service) + $context);
            ServiceActivated::dispatch($service);
        }

        return $result;
    }

    /**
     * @return array{0: ModuleResult, 1: bool} The result, and whether the account was created now.
     */
    private function runCreate(Service $service): array
    {
        $service->loadMissing('product', 'client', 'server');

        if ($service->status === ServiceStatus::Active) {
            return [ModuleResult::ok(__('The service is already active.')), false];
        }

        // A service cancelled or terminated while it waited is not set up anymore.
        if ($service->status !== ServiceStatus::Pending) {
            return [ModuleResult::fail(__('Only a service that waits to be set up can be created.')), false];
        }

        $module = $this->moduleFor($service);

        if ($module instanceof ModuleResult) {
            return [$this->failed($service, 'create', $module), false];
        }

        // Paid extras (resource options) are made only once the order is paid, for every module.
        if ($module instanceof HasResourceOptions) {
            $unpaid = $this->holdForExtrasPayment($service);

            if ($unpaid !== null) {
                return [$this->failed($service, 'create', $unpaid), false];
            }
        }

        // A new account goes only to a server that is on and has room: the one picked at order time,
        // or another server for the same module when that one was turned off or is full. A create
        // that may already have made an account stays on its server, where the module looks for it.
        // A module on the hardware inventory has no server record: the module finds its machine.
        $keepsServer = $service->server !== null && $module instanceof KeepsCreateServer && $module->keepsServer($service);

        if ($module !== null && ! $module instanceof UsesMachineInventory && ! $keepsServer && ($service->server === null || ! $this->takesNewAccount($service->server, $service))) {
            $server = $this->pickServer($service);

            if ($server === null) {
                return [$this->failed($service, 'create', ModuleResult::fail(__('No active :module server has free space.', ['module' => $module->name()]))), false];
            }

            $service->server()->associate($server);
            $service->save();
        }

        $result = $module === null ? ModuleResult::ok(__('Activated.')) : $this->attempt(fn (): ModuleResult => $module->create($service));

        if (! $result->success) {
            return [$this->failed($service, 'create', $result), false];
        }

        $service->fill(array_intersect_key($result->data, array_flip(['username', 'password'])));

        if (is_array($result->data['module_data'] ?? null)) {
            $service->module_data = array_merge((array) $service->module_data, $result->data['module_data']);
        }

        // The account details first, so they are kept even when the service is not activated below.
        $service->save();

        // Someone still has to finish the setup (staff install the OS by hand, say). The service
        // stays Pending with no welcome email; the next create, once that is done, activates it.
        if ($module instanceof FinishesSetupLater && $this->setupStillPending($module, $service)) {
            Activity::log('service.setup_waiting', "Service #{$service->id} ({$service->label()}) waits for staff to finish the setup: {$result->message}", $service);

            return [$result, false];
        }

        // Only a service that still waits is activated: staff may have cancelled its order while the
        // account was being made, and that cancel stands. An Active service without a due date
        // would never be billed.
        $activated = Service::query()->whereKey($service->id)->where('status', ServiceStatus::Pending)->update(['status' => ServiceStatus::Active]);

        if ($activated === 0) {
            return [$this->notActivated($service, $module), false];
        }

        $service->status = ServiceStatus::Active;
        $service->syncOriginalAttribute('status');

        // The module had its secret order fields (a first root password, say): they are not kept.
        app(OrderFields::class)->forgetSecrets($service);

        Activity::log('service.created', "Service #{$service->id} ({$service->label()}) set up: {$result->message}", $service);

        return [$result, true];
    }

    /**
     * The core payment wait for paid extras: a failed result while the order with the extras is not
     * settled (see OrderPaymentGate), with the WAITING flag saved so the setup runs again once the
     * invoice is paid. Null when the setup may go on; the flag is then removed.
     */
    private function holdForExtrasPayment(Service $service): ?ModuleResult
    {
        $data = (array) $service->module_data;

        if (OrderPaymentGate::extrasUnpaid($service)) {
            if (($data[OrderPaymentGate::WAITING] ?? null) !== true) {
                $service->module_data = array_merge($data, [OrderPaymentGate::WAITING => true]);
                $service->save();
            }

            return ModuleResult::fail(__('The extras on this order are not paid yet. The server is made once the order invoice is paid.'));
        }

        if (array_key_exists(OrderPaymentGate::WAITING, $data)) {
            $service->module_data = Arr::except($data, OrderPaymentGate::WAITING);
            $service->save();
        }

        return null;
    }

    /**
     * Whether the module says someone still has to finish the setup. A module that cannot tell is
     * treated as still waiting, so nothing is activated or emailed too early.
     */
    private function setupStillPending(FinishesSetupLater $module, Service $service): bool
    {
        try {
            return $module->setupPending($service);
        } catch (Throwable $exception) {
            $this->reportCleanly("Setup check of service #{$service->id}", $exception);

            return true;
        }
    }

    /**
     * The welcome email template and the extra context: the module's own (ChoosesWelcomeEmail), or
     * "service.welcome" (also when the module fails, or names a template that does not exist).
     * Context keys that look like a secret are removed: a welcome email never holds a password.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function welcomeEmail(Service $service): array
    {
        $module = $this->moduleFor($service);

        if (! $module instanceof ChoosesWelcomeEmail) {
            return ['service.welcome', []];
        }

        try {
            $welcome = $module->welcomeEmail($service);
        } catch (Throwable $exception) {
            $this->reportCleanly("Welcome email of service #{$service->id}", $exception);

            return ['service.welcome', []];
        }

        $key = $welcome['key'] ?? null;
        $context = $welcome['context'] ?? [];

        if (! is_string($key) || preg_match('/^[a-z0-9_]{1,50}(\.[a-z0-9_]{1,50}){1,3}$/D', $key) !== 1 || ! is_array($context)
            || ! EmailTemplate::query()->where('key', $key)->exists()) {
            return ['service.welcome', []];
        }

        // Keys starting with "_" steer the mailer (the language, chat copies), so a module cannot set them.
        $context = array_filter($context, fn (mixed $name): bool => is_string($name) && ! str_starts_with($name, '_'), ARRAY_FILTER_USE_KEY);

        return [$key, StandardSections::scrub($context)];
    }

    /**
     * The service stopped waiting while its account was being made: its order was cancelled, or
     * staff changed its status by hand. It stays as it is now. A service that should have nothing
     * on the server (cancelled, terminated or fraud) gets its new account removed again.
     */
    private function notActivated(Service $service, ?ServerModule $module): ModuleResult
    {
        $service->refresh();
        $subject = "Service #{$service->id} ({$service->label()}) was {$service->status->value} while it was being set up, so it was not activated.";

        if (in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
            Activity::log('service.module_failed', $subject.($module === null ? ' Check the service.' : ' Its new account was kept, so check the service.'), $service);

            return ModuleResult::fail(__('The service was changed while it was being set up, so it was not activated. Check the service.'));
        }

        if ($module === null) {
            Activity::log('service.module_failed', $subject, $service);

            return ModuleResult::fail(__('The service was cancelled while it was being set up, so it was not activated.'));
        }

        // Logged before the removal: removing a VPS can take minutes, and the job may be stopped first.
        Activity::log('service.module_failed', "{$subject} Removing its new account from the server.", $service);
        $removed = $this->attempt(fn (): ModuleResult => $module->terminate($service));

        if (! $removed->success) {
            Activity::log('service.module_failed', "Remove the new account of service #{$service->id} ({$service->label()}) from the server: it could not be removed: {$removed->message}", $service);

            return ModuleResult::fail(__('The service was cancelled while it was being set up. Remove the new account from the server.'));
        }

        Activity::log('service.account_removed', "Service #{$service->id} ({$service->label()}): the account made while it was {$service->status->value} was removed from the server.", $service);

        return ModuleResult::fail(__('The service was cancelled while it was being set up, so its new account was removed again.'));
    }

    /**
     * Suspend the service on its server. It waits up to 30 seconds for other work on the service
     * (a client's power action, say), so a suspended server is never started again under it. When
     * the service stays busy it fails, and the next nightly run tries again.
     *
     * @param  (Closure(): bool)|null  $stillDue  Asked inside the lock before the server is: false
     *                                            (the client paid meanwhile) changes nothing.
     */
    public function suspend(Service $service, string $reason, ?Closure $stillDue = null): ModuleResult
    {
        $lock = $this->lockFor($service, 120);

        if (! $this->waitFor($lock, 30)) {
            return $this->failed($service->loadMissing('product'), 'suspend', $this->busy());
        }

        try {
            // Read again inside the lock: the service may have changed while this waited.
            $service->refresh();

            if ($stillDue !== null && ! $stillDue()) {
                return ModuleResult::fail('no longer overdue');
            }

            [$result, $suspended] = $this->runSuspend($service, $reason);
        } finally {
            $lock->release();
        }

        // After the lock is free, so an automation started by the event can act on the service.
        if ($suspended) {
            $this->mailer->send('service.suspended', $service->client, TemplateMailer::serviceContext($service) + ['reason' => Locales::in(Locales::forClient($service->client), fn (): string => __($reason))]);
            ServiceSuspended::dispatch($service);
        }

        return $result;
    }

    /**
     * @return array{0: ModuleResult, 1: bool} The result, and whether the service was suspended now.
     */
    private function runSuspend(Service $service, string $reason): array
    {
        $service->loadMissing('product', 'client', 'server');

        if ($service->status === ServiceStatus::Suspended) {
            return [ModuleResult::ok(__('The service is already suspended.')), false];
        }

        // A pending, cancelled or terminated service has nothing to suspend, and once suspended it
        // could be unsuspended to Active without being set up or paid.
        if ($service->status !== ServiceStatus::Active) {
            return [ModuleResult::fail(__('Only an active service can be suspended.')), false];
        }

        $result = $this->runModule($service, fn (ServerModule $module): ModuleResult => $module->suspend($service, $reason));

        if (! $result->success) {
            return [$this->failed($service, 'suspend', $result), false];
        }

        $service->update([
            'status' => ServiceStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ]);

        Activity::log('service.suspended', "Service #{$service->id} ({$service->label()}) suspended: {$reason}", $service);

        return [$result, true];
    }

    /**
     * Unsuspend the service on its server. It never waits: when other work holds the service (a
     * resource change, say), or the module says its server is busy, an UnsuspendService job tries
     * again in the background, and the result is ok with data['queued'] = true. So a payment
     * webhook never blocks, and a paid service is not left suspended.
     */
    public function unsuspend(Service $service): ModuleResult
    {
        $result = $this->unsuspendNow($service);

        if ($result->success || ($result->data['busy'] ?? false) !== true) {
            return $result;
        }

        return $this->queueUnsuspend($service);
    }

    /**
     * Unsuspend the service now, for unsuspend() and the UnsuspendService job. When other work
     * holds the service, or the module says its server is busy, it fails with data['busy'] = true,
     * changes and logs nothing, and queues nothing.
     *
     * @param  int|null  $suspendedAt  When given, only the suspension that started then is lifted (see suspensionStart()): a newer one stays.
     */
    public function unsuspendNow(Service $service, ?int $suspendedAt = null): ModuleResult
    {
        $lock = $this->lockFor($service, 120);

        if (! $lock->get()) {
            return $this->busy();
        }

        try {
            // Read again inside the lock: the service may have changed meanwhile.
            $service->refresh();

            if ($suspendedAt !== null && $service->status === ServiceStatus::Suspended && $this->suspensionStart($service) !== $suspendedAt) {
                Activity::log('service.unsuspend_skipped', "Service #{$service->id} ({$service->label()}) stays suspended: it was suspended again after the unsuspend was asked for.", $service);

                return ModuleResult::fail(__('The service was suspended again in the meantime, so it stays suspended.'));
            }

            [$result, $unsuspended] = $this->runUnsuspend($service);
        } finally {
            $lock->release();
        }

        if ($unsuspended) {
            $this->mailer->send('service.unsuspended', $service->client, TemplateMailer::serviceContext($service));
        }

        return $result;
    }

    /**
     * Send an UnsuspendService job for a service that was busy, if it is still suspended.
     */
    private function queueUnsuspend(Service $service): ModuleResult
    {
        $current = $service->fresh();

        if ($current === null || $current->status !== ServiceStatus::Suspended) {
            return ModuleResult::ok(__('The service is not suspended.'));
        }

        // Logged first: on a queue that runs jobs at once, the job logs its own result before this returns.
        Activity::log('service.unsuspend_queued', "Service #{$current->id} ({$current->label()}): other work holds it on the server, so it is unsuspended in the background", $current);
        UnsuspendService::dispatch($current->id, $this->suspensionStart($current))->delay(UnsuspendService::BUSY_DELAY)->afterCommit();

        return ModuleResult::ok(__('This service is busy on the server. It will be unsuspended in a few minutes.'), ['queued' => true]);
    }

    /**
     * When the service's suspension started, in Unix seconds, or 0 when it has no date (an imported
     * service, say). Tells one suspension from a later one.
     */
    private function suspensionStart(Service $service): int
    {
        return $service->suspended_at?->getTimestamp() ?? 0;
    }

    /**
     * @return array{0: ModuleResult, 1: bool} The result, and whether the service was unsuspended now.
     */
    private function runUnsuspend(Service $service): array
    {
        $service->loadMissing('product', 'client', 'server');

        if ($service->status !== ServiceStatus::Suspended) {
            return [ModuleResult::ok(__('The service is not suspended.')), false];
        }

        $result = $this->runModule($service, fn (ServerModule $module): ModuleResult => $module->unsuspend($service));

        if (! $result->success) {
            // The module's own lock was busy: it is tried again later, nothing to log yet.
            return [($result->data['busy'] ?? false) === true ? $result : $this->failed($service, 'unsuspend', $result), false];
        }

        $service->update([
            'status' => ServiceStatus::Active,
            'suspended_at' => null,
            'suspension_reason' => null,
        ] + $this->withoutRecheck($service));

        Activity::log('service.unsuspended', "Service #{$service->id} ({$service->label()}) unsuspended", $service);

        return [$result, true];
    }

    /**
     * For the nightly run: run the module's suspend once more for suspended services flagged with
     * RECHECK_SUSPENSION, at most $limit per run. The flag goes when that works, or after its last
     * try. A service that failed waits behind the others. Each runs under the service lock, so an
     * unsuspend at the same time cannot be undone on the server; a service busy with other work is
     * left for the next night, without using a try. Returns how many were done.
     */
    public function recheckSuspensions(int $limit = 25): int
    {
        $done = 0;

        $services = Service::query()
            ->where('status', ServiceStatus::Suspended)
            ->whereNotNull('module_data->'.self::RECHECK_SUSPENSION)
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($services as $listed) {
            $lock = $this->lockFor($listed, 120);

            if (! $lock->get()) {
                continue;
            }

            try {
                if ($this->recheckSuspension($listed)) {
                    $done++;
                }
            } finally {
                $lock->release();
            }
        }

        return $done;
    }

    /**
     * Run the module's suspend once more for one listed service, under its lock. True when it worked.
     */
    private function recheckSuspension(Service $listed): bool
    {
        // Read again inside the lock: each call may wait minutes on its node, and a service
        // unsuspended, removed or deleted meanwhile must not be stopped.
        $service = $listed->fresh(['product', 'client', 'server']);

        if ($service === null || $service->status !== ServiceStatus::Suspended || ! array_key_exists(self::RECHECK_SUSPENSION, (array) $service->module_data)) {
            return false;
        }

        $result = $this->runModule($service, fn (ServerModule $module): ModuleResult => $module->suspend($service, (string) $service->suspension_reason));
        $data = (array) $service->module_data;
        $triesLeft = $result->success ? 0 : (int) ($data[self::RECHECK_SUSPENSION] ?? 0) - 1;

        if ($result->success) {
            Activity::log('service.suspension_checked', "Suspension of service #{$service->id} ({$service->label()}) checked again on the server: {$result->message}", $service);
        } else {
            Activity::log('service.module_failed', "Could not recheck the suspension of service #{$service->id} ({$service->label()}): {$result->message}".($triesLeft > 0 ? '' : ' That was the last try, so check it on the server.'), $service);
        }

        $service->module_data = $triesLeft > 0 ? array_merge($data, [self::RECHECK_SUSPENSION => $triesLeft]) : Arr::except($data, self::RECHECK_SUSPENSION);
        $service->save();

        return $result->success;
    }

    /**
     * The update that removes the RECHECK_SUSPENSION flag, or none when the service has no flag.
     * A service that was unsuspended or terminated is not suspended again by the nightly run.
     *
     * @return array<string, mixed>
     */
    private function withoutRecheck(Service $service): array
    {
        $data = (array) $service->module_data;

        return array_key_exists(self::RECHECK_SUSPENSION, $data) ? ['module_data' => Arr::except($data, self::RECHECK_SUSPENSION)] : [];
    }

    /**
     * Remove the service from its server. Not while other work holds it (a setup would finish and
     * make it active again): then it fails with data['busy'] = true and changes nothing.
     *
     * @param  (Closure(): bool)|null  $stillDue  Asked inside the lock before the server is: false
     *                                            (the client paid meanwhile) changes nothing.
     */
    public function terminate(Service $service, ?Closure $stillDue = null): ModuleResult
    {
        $lock = $this->lockFor($service);

        if (! $lock->get()) {
            return $this->busy();
        }

        try {
            // Read again inside the lock: a setup that just finished left an account to remove.
            $service->refresh();

            if ($stillDue !== null && ! $stillDue()) {
                return ModuleResult::fail('no longer overdue');
            }

            [$result, $terminated] = $this->runTerminate($service);
        } finally {
            $lock->release();
        }

        if ($terminated) {
            ServiceTerminated::dispatch($service);
        }

        return $result;
    }

    /**
     * @return array{0: ModuleResult, 1: bool} The result, and whether the service was terminated now.
     */
    private function runTerminate(Service $service): array
    {
        $service->loadMissing('product', 'client', 'server');

        if ($service->status === ServiceStatus::Terminated) {
            return [ModuleResult::ok(__('The service is already terminated.')), false];
        }

        $wasProvisioned = in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true);

        $result = $wasProvisioned
            ? $this->runModule($service, fn (ServerModule $module): ModuleResult => $module->terminate($service))
            : ModuleResult::ok(__('Nothing to remove on the server.'));

        if (! $result->success) {
            return [$this->failed($service, 'terminate', $result), false];
        }

        // A removed service keeps no order form values (App\Provisioning\OrderFields).
        $service->update([
            'status' => ServiceStatus::Terminated,
            'terminated_at' => now(),
            'next_due_date' => null,
            'order_fields' => null,
        ] + $this->withoutRecheck($service));

        Activity::log('service.terminated', "Service #{$service->id} ({$service->label()}) terminated", $service);

        return [$result, true];
    }

    /**
     * Move the service to its current plan on the server. A module whose plan change is slow
     * (QueuesPackageChanges) gets it in a queued job (ChangeServicePackage), so a payment webhook
     * that applied the plan change never waits for the server; the result then says it was queued.
     * Other modules change it now under the service lock; when other work holds the service, the
     * same job sets the plan once that work ends.
     */
    public function changePackage(Service $service): ModuleResult
    {
        $service->loadMissing('product', 'client', 'server');

        if ($this->moduleFor($service) instanceof QueuesPackageChanges) {
            return $this->queuePackageChange($service);
        }

        $lock = $this->lockFor($service);

        if (! $lock->get()) {
            return $this->queuePackageChange($service);
        }

        try {
            // Read again inside the lock: the plan, or the service itself, may have changed meanwhile.
            $service->refresh();
            $service->loadMissing('product', 'client', 'server');

            $result = $this->runModule($service, fn (ServerModule $module): ModuleResult => $module->changePackage($service));
        } finally {
            $lock->release();
        }

        if (! $result->success) {
            // The module's own lock was busy: the job sets the plan later, nothing to log yet.
            return ($result->data['busy'] ?? false) === true ? $this->queuePackageChange($service) : $this->failed($service, 'change package', $result);
        }

        Activity::log('service.package_changed', "Service #{$service->id} ({$service->label()}) package updated on the server", $service);

        return $result;
    }

    /**
     * Send a ChangeServicePackage job, which sets the plan the service has when it runs.
     */
    private function queuePackageChange(Service $service): ModuleResult
    {
        // The job skips other services, so say so now instead of promising a change.
        if (! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
            return ModuleResult::fail(__('Only an active or suspended service can change its plan on the server.'));
        }

        // Logged first: on a queue that runs jobs at once, the job logs its own result before this returns.
        Activity::log('service.package_queued', "Service #{$service->id} ({$service->label()}): the new plan is being set on the server in the background", $service);
        ChangeServicePackage::dispatch($service->id)->afterCommit();

        return ModuleResult::ok(__('The new plan is being set on the server. This can take a few minutes.'), ['queued' => true]);
    }

    /**
     * Move the service to its current plan on the server now, for the ChangeServicePackage job. It
     * waits up to 60 seconds for other work on the service; when that does not end, it fails with
     * data['busy'] = true and changes nothing.
     */
    public function changePackageNow(Service $service): ModuleResult
    {
        $lock = $this->lockFor($service);

        if (! $this->waitFor($lock, 60)) {
            return $this->busy();
        }

        try {
            // Read again inside the lock: the plan, or the service itself, may have changed meanwhile.
            $service->refresh();
            $service->loadMissing('product', 'client', 'server');

            if (! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
                return ModuleResult::fail(__('Only an active or suspended service can change its plan on the server.'));
            }

            $module = $this->moduleFor($service);
            $result = match (true) {
                $module === null => ModuleResult::ok(__('No server module; status updated only.')),
                $module instanceof ModuleResult => $module,
                default => $this->attemptCleanly(fn (): ModuleResult => $module->changePackage($service), "Plan change of service #{$service->id}"),
            };

            if (! $result->success) {
                // The module's own lock was busy: the job tries again later, nothing to log yet.
                return ($result->data['busy'] ?? false) === true ? $result : $this->failed($service, 'change package', $result);
            }

            Activity::log('service.package_changed', "Service #{$service->id} ({$service->label()}) package updated on the server", $service);

            return $result;
        } finally {
            $lock->release();
        }
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
     * The client's one-time sign-in link to the panel of an active service. A module that signs in
     * with the client's context (SignsInWithContext) gets it, and only an https link to the client's
     * own service is given back; other modules give their loginUrl(). The link is never stored or
     * logged.
     */
    public function signInUrl(Service $service, ClientContext $context): ?string
    {
        $service->loadMissing('product', 'server');
        $module = $this->moduleFor($service);

        if (! $module instanceof SignsInWithContext) {
            return $this->loginUrl($service);
        }

        if ($service->status !== ServiceStatus::Active || (int) $service->client_id !== $context->clientId) {
            return null;
        }

        try {
            $url = $module->signInUrl($service, $context);
        } catch (Throwable $exception) {
            $this->reportCleanly("Panel sign-in of service #{$service->id}", $exception);

            return null;
        }

        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }

    /**
     * The module's checks of why a client's panel sign-in may fail, for staff ("Test panel
     * sign-in"), or an empty list when the module has none. Each row has a label, a status (ok,
     * warn, fail or skip) and a detail.
     *
     * @return list<array{label: string, status: 'ok'|'warn'|'fail'|'skip', detail: string}>
     */
    public function checkClientAccess(Service $service): array
    {
        $service->loadMissing('product', 'client', 'server');
        $module = $this->moduleFor($service);

        if (! $module instanceof ChecksClientAccess) {
            return [];
        }

        try {
            $rows = $module->checkClientAccess($service);
        } catch (Throwable $exception) {
            $this->reportCleanly("Panel access check of service #{$service->id}", $exception);

            return [['label' => __('Panel check'), 'status' => 'fail', 'detail' => __('The check stopped with an error (:error).', ['error' => class_basename($exception)])]];
        }

        $checks = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['label'] ?? null)) {
                continue;
            }

            $status = $row['status'] ?? 'fail';

            $checks[] = [
                'label' => Str::limit($row['label'], 100),
                'status' => in_array($status, ['ok', 'warn', 'fail', 'skip'], true) ? $status : 'fail',
                'detail' => Str::limit(is_string($row['detail'] ?? null) ? $row['detail'] : '', 500),
            ];
        }

        return $checks;
    }

    /**
     * The fields of the staff form that links the service to an account that already exists on the
     * panel, or an empty list when the module cannot do that.
     *
     * @return array<string, array{label: string, type: string, help?: string, required?: bool}>
     */
    public function linkFields(Service $service): array
    {
        $module = $this->moduleFor($service->loadMissing('product'));

        if (! $module instanceof LinksExistingAccount) {
            return [];
        }

        try {
            $fields = $module->linkFields();
        } catch (Throwable $exception) {
            $this->reportCleanly("Link fields of service #{$service->id}", $exception);

            return [];
        }

        return array_filter(
            $fields,
            fn (mixed $field, mixed $name): bool => is_string($name) && preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $name) === 1
                && is_array($field) && is_string($field['label'] ?? null) && is_string($field['type'] ?? null),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Link the service to an account that already exists on the panel (staff only). Only the
     * module's own fields reach it. On success its module_data is added to the service's and
     * service.account_linked is logged.
     *
     * @param  array<string, mixed>  $input
     */
    public function linkAccount(Service $service, array $input): ModuleResult
    {
        $service->loadMissing('product', 'client', 'server');
        $module = $this->moduleFor($service);

        if (! $module instanceof LinksExistingAccount) {
            return ModuleResult::fail(__('This server module cannot link an existing account.'));
        }

        $fields = $this->linkFields($service);
        $input = array_map(fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', array_intersect_key($input, $fields));

        $lock = $this->lockFor($service, 120);

        if (! $lock->get()) {
            return $this->busy();
        }

        try {
            $service->refresh();
            $service->loadMissing('product', 'client', 'server');

            $result = $this->attemptCleanly(fn (): ModuleResult => $module->linkExistingAccount($service, $input), "Account link of service #{$service->id}");

            if (! $result->success) {
                return $this->failed($service, 'link an account to', $result);
            }

            if (is_array($result->data['module_data'] ?? null)) {
                $service->module_data = array_merge((array) $service->module_data, $result->data['module_data']);
                $service->save();
            }

            Activity::log('service.account_linked', "Service #{$service->id} ({$service->label()}) linked to an existing account: {$result->message}", $service);

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * The client server panel for the service page: the full panel of a module with panel sections
     * (see PanelGate::serverPanel), or exactly clientPanel() for other modules.
     *
     * @return array<string, mixed>|null
     */
    public function serverPanel(Service $service, ClientContext $context): ?array
    {
        $service->loadMissing('product', 'server');
        $module = $this->moduleFor($service);

        if ($module instanceof HasPanelSections) {
            return $this->panelGate()->serverPanel($service, $module, $context);
        }

        return $this->clientPanel($service);
    }

    /**
     * Read one section of the client server panel, through every check of the panel gate.
     *
     * @param  array<string, mixed>  $query
     */
    public function panelRead(Service $service, string $section, array $query, ClientContext $context): PanelResult
    {
        $module = $this->moduleFor($service->loadMissing('product', 'server'));

        if (! $module instanceof ServerModule) {
            return $this->noPanel();
        }

        return $this->panelGate()->read($service, $module, $section, $query, $context);
    }

    /**
     * Run one action of the client server panel, through every check of the panel gate.
     *
     * @param  array<string, mixed>  $input
     * @param  bool  $canReveal  Whether the answer can show a secret once (the browser asked for JSON).
     */
    public function panelAction(Service $service, string $section, string $action, array $input, ClientContext $context, bool $canReveal): PanelResult
    {
        $module = $this->moduleFor($service->loadMissing('product', 'server'));

        if (! $module instanceof ServerModule) {
            return $this->noPanel();
        }

        return $this->panelGate()->action($service, $module, $section, $action, $input, $context, $canReveal);
    }

    /**
     * Open the browser console of the service: on success data['ticket'] is the single-use ticket
     * handle and data['source'] the wss://host:port the console page may connect to.
     */
    public function openConsole(Service $service, ClientContext $context): PanelResult
    {
        $module = $this->moduleFor($service->loadMissing('product', 'server'));

        if (! $module instanceof ServerModule) {
            return $this->noPanel();
        }

        return $this->panelGate()->openConsole($service, $module, $context);
    }

    /**
     * The resource kinds staff may sell for the product: the module's (HasResourceOptions), checked
     * one by one, or every built-in kind for a product without a module (set by hand), or none for
     * a module without the contract. A kind with a bad key, unit or ladder, or a built-in kind with
     * another unit, is left out and logged once a day.
     *
     * @return array<string, ResourceKind>
     *
     * @throws InvalidArgumentException With a message for staff when the module is not installed or cannot list its kinds.
     */
    public function resourceKinds(Product $product): array
    {
        $slug = (string) $product->server_module;

        if ($slug === '') {
            return ResourceKinds::forProductWithoutModule();
        }

        try {
            $module = $this->extensions->serverModule($slug);
        } catch (Throwable) {
            throw new InvalidArgumentException(__('The server module ":module" is not installed.', ['module' => $slug]));
        }

        if (! $module instanceof HasResourceOptions) {
            return [];
        }

        try {
            $declared = $module->resourceKinds($product);
        } catch (InvalidArgumentException $exception) {
            // By contract a message for staff about the product's module settings.
            throw new InvalidArgumentException($exception->getMessage());
        } catch (Throwable $exception) {
            // Staff screens may ask again and again; once an hour is enough to find it in the log.
            if (Cache::add('nuvabill:resource-kinds-failed:product:'.$product->id, true, 3600)) {
                $this->reportCleanly("Resource kinds of product #{$product->id}", $exception);
            }

            throw new InvalidArgumentException(__('The server module could not list its resources. Check its settings and try again.'));
        }

        $kinds = [];

        foreach ($declared as $key => $kind) {
            $problem = $this->resourceKindProblem($slug, $key, $kind);

            if ($problem !== null) {
                $this->logSkippedKind($product, $slug, (string) $key, $problem);

                continue;
            }

            $kinds[$kind->key] = $kind;
        }

        return $kinds;
    }

    /**
     * What the product's plan gives without extras, per kind in units (null where unknown), in
     * data['base']. It may ask the server: the product's own, else the first active one of its
     * module, or none for a module on the hardware inventory. For staff screens only.
     */
    public function baseResources(Product $product): ModuleResult
    {
        $slug = (string) $product->server_module;

        if ($slug === '') {
            return ModuleResult::ok('', ['base' => array_fill_keys(array_keys(ResourceKinds::BUILT_IN), null)]);
        }

        try {
            $module = $this->extensions->serverModule($slug);
        } catch (Throwable) {
            return ModuleResult::fail(__('The server module ":module" is not installed.', ['module' => $slug]));
        }

        if (! $module instanceof HasResourceOptions) {
            return ModuleResult::fail(__('This server module cannot sell resource options.'));
        }

        $server = $module instanceof UsesMachineInventory ? null : ($product->server ?? Server::query()->where('module', $slug)->where('is_active', true)->orderBy('id')->first());

        try {
            $base = $module->baseResources($product, $server);
        } catch (InvalidArgumentException $exception) {
            return ModuleResult::fail($exception->getMessage());
        } catch (Throwable $exception) {
            if (Cache::add('nuvabill:base-resources-failed:product:'.$product->id, true, 3600)) {
                $this->reportCleanly("Plan resources of product #{$product->id}", $exception);
            }

            return ModuleResult::fail(__('The server did not answer. Check the server and try again.'));
        }

        $clean = [];

        foreach ($base as $kind => $units) {
            if (is_string($kind) && ResourceKind::isValidKey($kind)) {
                $clean[$kind] = is_int($units) && $units >= 0 ? $units : null;
            }
        }

        return ModuleResult::ok('', ['base' => $clean]);
    }

    /**
     * Live limits for the service's extras, per kind in extra units, kept 60 seconds. When the
     * module cannot tell, no kind may grow for now (each kind's most is what the service has), so
     * nothing is sold that the server may not take; removals stay possible.
     *
     * @return array<string, ResourceLimit>
     */
    public function resourceLimits(Service $service): array
    {
        $service->loadMissing('product', 'server');
        $module = $this->moduleFor($service);

        if (! $module instanceof HasResourceOptions) {
            return [];
        }

        $extras = $service->resourceExtras();
        // A change of the extras gives a new key, so limits are read again after every change.
        $key = 'nuvabill:resource-limits:'.$service->id.':'.app()->getLocale().':'.sha1((string) json_encode($extras->units));
        $cached = Cache::get($key);

        if (! is_array($cached)) {
            $cached = $this->readLimits($module, $service, $extras);
            Cache::put($key, $cached, self::LIMITS_SECONDS);
        }

        $limits = [];

        foreach ($cached as $kind => $limit) {
            if (is_string($kind) && is_array($limit)) {
                $limits[$kind] = new ResourceLimit($limit['min'] ?? null, $limit['max'] ?? null, $limit['reason'] ?? null);
            }
        }

        return $limits;
    }

    /**
     * The module's limits as plain arrays (the cache keeps no objects), or the safe fallback when it
     * fails.
     *
     * @return array<string, array{min: int|null, max: int|null, reason: string|null}>
     */
    private function readLimits(HasResourceOptions $module, Service $service, ResourceExtras $extras): array
    {
        try {
            $limits = [];

            foreach ($module->resourceLimits($service) as $kind => $limit) {
                if (is_string($kind) && $limit instanceof ResourceLimit) {
                    $limits[$kind] = ['min' => $limit->minUnits, 'max' => $limit->maxUnits, 'reason' => $limit->reason];
                }
            }

            return $limits;
        } catch (Throwable $exception) {
            if (Cache::add('nuvabill:resource-limits-failed:service:'.$service->id, true, 300)) {
                $this->reportCleanly("Resource limits of service #{$service->id}", $exception);
            }

            $reason = __('We cannot check your server right now. Try again later.');

            return array_map(fn (mixed $units): array => ['min' => null, 'max' => max(0, (int) $units), 'reason' => $reason], $extras->units);
        }
    }

    /**
     * Set the service's server to its plan plus its extras ($service->resourceExtras()), for the
     * resource change job and staff. It holds the service lock; when other work holds it, it fails
     * with data['busy'] = true and changes nothing. Without a module staff set it by hand
     * (data['manual'] = true). The module's data flags (manual, restart, pending) come back as they are.
     */
    public function applyResources(Service $service): ModuleResult
    {
        $lock = $this->lockFor($service);

        if (! $lock->get()) {
            return $this->busy();
        }

        try {
            // Read again inside the lock: the extras or the service may have changed meanwhile.
            $service->refresh();

            return $this->runApplyResources($service);
        } finally {
            $lock->release();
        }
    }

    private function runApplyResources(Service $service): ModuleResult
    {
        $service->loadMissing('product', 'client', 'server');

        if (! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
            return ModuleResult::fail(__('Only an active or suspended service can change its resources on the server.'));
        }

        $module = $this->moduleFor($service);

        if ($module === null) {
            return ModuleResult::ok(__('No server module: set it by hand.'), ['manual' => true]);
        }

        if ($module instanceof ModuleResult) {
            return $this->failed($service, 'set the resources of', $module);
        }

        $extras = $service->resourceExtras();

        if (! $module instanceof HasResourceOptions) {
            return $extras->isEmpty()
                ? ModuleResult::ok(__('Nothing to set on the server.'))
                : $this->failed($service, 'set the resources of', ModuleResult::fail(__('This server module cannot change resources.')));
        }

        try {
            $kinds = array_keys($this->resourceKinds($service->product));
        } catch (InvalidArgumentException $exception) {
            return $this->failed($service, 'set the resources of', ModuleResult::fail($exception->getMessage()));
        }

        $this->logUnsupportedKinds($service, $extras, $kinds);

        $result = $this->attemptCleanly(fn (): ModuleResult => $module->applyResources($service, $extras->only($kinds)), "Resources of service #{$service->id}");

        if (! $result->success) {
            // The module's own lock was busy: the job tries again later, nothing to log yet.
            return ($result->data['busy'] ?? false) === true ? $result : $this->failed($service, 'set the resources of', $result);
        }

        Activity::log('service.resources_applied', "Resources of service #{$service->id} ({$service->label()}) set on the server: {$result->message}", $service);

        return $result;
    }

    /**
     * Read the live server and compare it with the plan plus the service's extras. Null when the
     * service has no module that sells resources, or the server did not answer. Changes nothing.
     */
    public function checkResources(Service $service): ?ResourceCheck
    {
        $service->loadMissing('product', 'client', 'server');
        $module = $this->moduleFor($service);

        if (! $module instanceof HasResourceOptions) {
            return null;
        }

        try {
            $kinds = array_keys($this->resourceKinds($service->product));

            return $module->checkResources($service, $service->resourceExtras()->only($kinds));
        } catch (Throwable $exception) {
            $this->reportCleanly("Resource check of service #{$service->id}", $exception);

            return null;
        }
    }

    /**
     * Why a kind the module declared cannot be used, in plain English for the log, or null when it
     * is fine.
     */
    private function resourceKindProblem(string $slug, mixed $key, mixed $kind): ?string
    {
        if (! $kind instanceof ResourceKind) {
            return 'it is not a ResourceKind';
        }

        if ($key !== $kind->key || ! ResourceKind::isValidKey($kind->key)) {
            return 'its key is not valid, or not the same as its array key';
        }

        if (! ResourceKind::isValidUnit($kind->unit) || trim($kind->label) === '') {
            return 'its unit or label is not valid';
        }

        $builtIn = ResourceKinds::BUILT_IN[$kind->key] ?? null;

        if ($builtIn !== null && $kind->unit !== $builtIn['unit']) {
            return "a built-in kind must use the unit \"{$builtIn['unit']}\"";
        }

        if ($builtIn === null && ! str_starts_with($kind->key, str_replace('-', '_', $slug).'_')) {
            return 'a module\'s own kind must start with the module name';
        }

        if ($kind->ladder !== null && ($builtIn !== null || ! $this->validLadder($kind->ladder))) {
            return 'its ladder is not valid';
        }

        if ($kind->minStep < 1 || $kind->stepMultiple < 1 || ($kind->maxExtra !== null && $kind->maxExtra < 1)) {
            return 'its step or maximum is not valid';
        }

        return null;
    }

    /**
     * A ladder is a list of at least two step names.
     *
     * @param  array<mixed>  $ladder
     */
    private function validLadder(array $ladder): bool
    {
        if (! array_is_list($ladder) || count($ladder) < 2) {
            return false;
        }

        foreach ($ladder as $label) {
            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 100) {
                return false;
            }
        }

        return true;
    }

    private function logSkippedKind(Product $product, string $slug, string $key, string $problem): void
    {
        if (! Cache::add('nuvabill:resource-kind-skipped:'.$product->id.':'.sha1($key.'|'.$problem), true, 86400)) {
            return;
        }

        $safeKey = Str::limit((string) preg_replace('/[^A-Za-z0-9_-]/', '?', $key), 40);

        Log::warning("Server module \"{$slug}\": resource kind \"{$safeKey}\" of product #{$product->id} was left out: {$problem}.");
    }

    /**
     * Log once a day each extra the service has that its module does not sell: it is not set on
     * the server.
     *
     * @param  list<string>  $kinds  The module's kinds.
     */
    private function logUnsupportedKinds(Service $service, ResourceExtras $extras, array $kinds): void
    {
        foreach ($extras->units as $kind => $units) {
            if ($units > 0 && ! in_array($kind, $kinds, true) && Cache::add('nuvabill:resources-unsupported:'.$service->id.':'.$kind, true, 86400)) {
                Activity::log('service.resources_failed', "Service #{$service->id} ({$service->label()}): kind {$kind} is not supported by the module, so it was not set on the server.", $service);
            }
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

        if (! $module instanceof HasClientPanel || $service->status !== ServiceStatus::Active || ! $this->hasPlace($service, $module)) {
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
     * The live state of the module's client panel (running, stopped, suspended or unknown), for the
     * panel's checks after a power action, or null when the service has no panel. A module that can
     * read just the state does so; a module with panel sections gives the state of its cached
     * overview; others build the whole panel. The panel checks every few seconds, so a failure is
     * reported at most once in five minutes for each service.
     */
    public function clientPanelState(Service $service): ?string
    {
        $service->loadMissing('product', 'server');
        $module = $this->moduleFor($service);

        if ((! $module instanceof HasClientPanel && ! $module instanceof HasPanelSections) || $service->status !== ServiceStatus::Active || ! $this->hasPlace($service, $module)) {
            return null;
        }

        try {
            $data = match (true) {
                $module instanceof HasPanelState => ['state' => $module->clientPanelState($service)],
                $module instanceof HasPanelSections => ['state' => $this->panelGate()->overviewState($service, $module)],
                default => $module->clientPanel($service),
            };
        } catch (Throwable $exception) {
            if (Cache::add('nuvabill:panel-state-failed:service:'.$service->id, true, 300)) {
                report($exception);
            }

            return 'unknown';
        }

        $state = empty($data['error']) ? ($data['state'] ?? null) : null;

        return in_array($state, ['running', 'stopped', 'suspended'], true) ? $state : 'unknown';
    }

    /**
     * Run a client panel action (for example "restart") and log it. It holds the service lock, so
     * it never runs on top of a suspend, terminate, resource change or plan change: when one is
     * running it fails with data['busy'] = true, and a service suspended meanwhile is refused.
     *
     * The answer is for the client. When the module could not reach its server, the module's own
     * words (which name the server) go to the activity log for staff, and the client gets a plain
     * message without them.
     *
     * @param  array<string, mixed>  $input
     */
    public function clientAction(Service $service, string $action, array $input): ModuleResult
    {
        $service->loadMissing('product', 'server', 'client');

        if ($this->clientActionModule($service, $action) === null) {
            return ModuleResult::fail(__('This action is not available.'));
        }

        $lock = $this->lockFor($service, 120);

        if (! $lock->get()) {
            return $this->busy();
        }

        try {
            // Read again inside the lock: a suspend or terminate may have finished meanwhile.
            $service->refresh();
            $service->loadMissing('product', 'server', 'client');
            $module = $this->clientActionModule($service, $action);

            if ($module === null) {
                return ModuleResult::fail(__('This action is not available.'));
            }

            $stopped = false;

            try {
                $result = $module->clientAction($service, $action, $input);
            } catch (Throwable $exception) {
                $this->reportCleanly("Client action {$action} of service #{$service->id}", $exception);
                $result = ModuleResult::fail(__('The server module stopped with an error (:error). Check the server and try again.', ['error' => class_basename($exception)]));
                $stopped = true;
            }
        } finally {
            $lock->release();
        }

        Activity::log(
            $result->success ? 'service.client_action' : 'service.module_failed',
            "Client action \"{$action}\" on service #{$service->id} ({$service->label()}): {$result->message}",
            $service,
        );

        if ($stopped || (! $result->success && $this->tellsWhereTheServerIs($result->message, $service->server))) {
            return ModuleResult::fail(__('The server did not answer. Please try again in a minute.'), $result->data);
        }

        return $result;
    }

    /**
     * Whether a module's failure text is for staff only: it names the server's host or IP address,
     * holds a web address, or carries a raw connection error (which ends with the address called).
     */
    private function tellsWhereTheServerIs(string $message, ?Server $server): bool
    {
        if (preg_match('~[a-z][a-z0-9+.-]*://|\bcURL error\b~i', $message) === 1) {
            return true;
        }

        foreach (array_filter([(string) $server?->hostname, (string) $server?->ip_address]) as $address) {
            if (preg_match('~(?<![\w.-])'.preg_quote($address, '~').'(?![\w-])~iu', $message) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The module that runs the client panel action, or null when the service is not active or its
     * module's client panel has no such action.
     */
    private function clientActionModule(Service $service, string $action): ?HasClientPanel
    {
        $module = $this->moduleFor($service);

        return $module instanceof HasClientPanel && $service->status === ServiceStatus::Active && array_key_exists($action, $module->clientActions()) ? $module : null;
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

        if (! $module instanceof HasLoginForm || $service->status !== ServiceStatus::Active || ! $this->hasPlace($service, $module)) {
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

    /**
     * Whether the service still waits to be set up, and the last setup was refused only because the
     * order invoice was not paid yet: its paid extras (the core wait, see OrderPaymentGate), or the
     * module's own check. Such a setup may run again once that invoice is paid. Read from the
     * database, as a setup may have run since the service was loaded.
     */
    public function waitsForPayment(Service $service): bool
    {
        $current = $service->fresh(['product']);

        if ($current?->product === null || $current->status !== ServiceStatus::Pending) {
            return false;
        }

        if ((((array) $current->module_data)[OrderPaymentGate::WAITING] ?? null) === true) {
            return true;
        }

        $module = $this->moduleFor($current);

        if (! $module instanceof WaitsForPayment) {
            return false;
        }

        try {
            return $module->waitsForPayment($current);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
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
     * Whether the service has a place to run: a server record, or a machine from the hardware
     * inventory that its module finds itself.
     */
    private function hasPlace(Service $service, ServerModule $module): bool
    {
        return $service->server !== null || $module instanceof UsesMachineInventory;
    }

    /**
     * The panel gate, made when needed: it uses this class too.
     */
    private function panelGate(): PanelGate
    {
        return app(PanelGate::class);
    }

    private function noPanel(): PanelResult
    {
        return PanelResult::fail(__('This feature is not available for this service.'), null, 404);
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

    /**
     * Like attempt(), but an exception's message is never kept, shown or reported: a raw HTTP error
     * may hold a server address with a key. Staff see only the kind of error.
     *
     * @param  Closure(): ModuleResult  $callback
     * @param  string  $what  What was running, for the report, such as "Resources of service #12".
     */
    private function attemptCleanly(Closure $callback, string $what): ModuleResult
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            $this->reportCleanly($what, $exception);

            return ModuleResult::fail(__('The server module stopped with an error (:error). Check the server and try again.', ['error' => class_basename($exception)]));
        }
    }

    /**
     * Report an exception from a module without its message or the exceptions before it.
     */
    private function reportCleanly(string $what, Throwable $exception): void
    {
        report(new RuntimeException("{$what}: ".class_basename($exception)));
    }

    /**
     * Wait up to the given seconds for the lock. False when other work kept it all that time.
     */
    private function waitFor(Lock $lock, int $seconds): bool
    {
        try {
            return (bool) $lock->block($seconds);
        } catch (LockTimeoutException) {
            return false;
        }
    }

    /**
     * The result when other work holds the service: nothing changed, try again later.
     */
    private function busy(): ModuleResult
    {
        return ModuleResult::fail(__('This service is busy on the server. Try again in a minute.'), ['busy' => true]);
    }

    private function failed(Service $service, string $action, ModuleResult $result): ModuleResult
    {
        Activity::log('service.module_failed', "Could not {$action} service #{$service->id} ({$service->label()}): {$result->message}", $service);

        return $result;
    }

    /**
     * Whether the server can take this service as a new account: it is on and has room for it.
     */
    private function takesNewAccount(Server $server, Service $service): bool
    {
        if (! $server->is_active) {
            return false;
        }

        return $server->max_accounts === null || $server->accounts()->whereKeyNot($service->id)->count() < $server->max_accounts;
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
