<?php

namespace Tests\Feature\Servers;

use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\Provisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A service is set up on its server once, however many paths ask for it at the same time, and only
 * services in the right state are suspended.
 */
class ProvisioningLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']])]);
    }

    public function test_create_is_refused_while_another_setup_holds_the_lock(): void
    {
        $service = $this->cpanelService(ServiceStatus::Pending);
        $lock = Cache::lock(Provisioner::LOCK_PREFIX.$service->id, 900);
        $this->assertTrue($lock->get());

        $result = app(Provisioner::class)->create($service);

        $this->assertFalse($result->success);
        $this->assertSame('This service is being set up right now. Check again in a few minutes.', $result->message);
        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
        Http::assertNothingSent();

        // Once the other setup is done, the lock is free again.
        $lock->release();
        $this->assertTrue(app(Provisioner::class)->create($service)->success);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_create_reads_the_service_again_so_an_old_copy_does_not_make_a_second_account(): void
    {
        $service = $this->cpanelService(ServiceStatus::Pending);
        $stale = Service::query()->findOrFail($service->id);

        // Another path finished the setup after this copy was loaded.
        Service::query()->whereKey($service->id)->update(['status' => ServiceStatus::Active, 'username' => 'razacc']);

        $result = app(Provisioner::class)->create($stale);

        $this->assertTrue($result->success);
        $this->assertSame('The service is already active.', $result->message);
        $this->assertSame('razacc', $stale->username);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        $this->assertSame(0, ActivityLog::query()->where('action', 'service.created')->count());
    }

    public function test_a_service_terminated_while_it_waited_is_not_set_up(): void
    {
        $service = $this->cpanelService(ServiceStatus::Pending);
        $stale = Service::query()->findOrFail($service->id);
        Service::query()->whereKey($service->id)->update(['status' => ServiceStatus::Terminated]);

        $result = app(Provisioner::class)->create($stale);

        $this->assertFalse($result->success);
        $this->assertSame(ServiceStatus::Terminated, $service->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_terminate_waits_for_a_setup_in_progress(): void
    {
        $service = $this->cpanelService(ServiceStatus::Pending);
        $lock = Cache::lock(Provisioner::LOCK_PREFIX.$service->id, 900);
        $lock->get();

        $result = app(Provisioner::class)->terminate($service);

        $this->assertFalse($result->success);
        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
        $lock->release();
    }

    public function test_terminate_removes_an_account_a_setup_created_after_the_copy_was_loaded(): void
    {
        $service = $this->cpanelService(ServiceStatus::Pending);
        $stale = Service::query()->findOrFail($service->id);
        Service::query()->whereKey($service->id)->update(['status' => ServiceStatus::Active, 'username' => 'razacc']);

        $this->assertTrue(app(Provisioner::class)->terminate($stale)->success);

        $this->assertSame(ServiceStatus::Terminated, $service->fresh()->status);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/json-api/removeacct') && $request['username'] === 'razacc');
    }

    public function test_only_an_active_service_can_be_suspended(): void
    {
        $product = Product::factory()->create(['server_module' => null]);

        foreach ([ServiceStatus::Pending, ServiceStatus::Cancelled, ServiceStatus::Terminated] as $status) {
            $service = Service::factory()->for($this->client())->create(['product_id' => $product->id, 'status' => $status]);

            $result = app(Provisioner::class)->suspend($service, 'Abuse report');

            $this->assertFalse($result->success, $status->value);
            $this->assertSame('Only an active service can be suspended.', $result->message);
            $this->assertSame($status, $service->fresh()->status);
            $this->assertNull($service->fresh()->suspended_at);
        }

        Mail::assertNothingSent();

        $active = Service::factory()->for($this->client())->create(['product_id' => $product->id]);
        $this->assertTrue(app(Provisioner::class)->suspend($active, 'Abuse report')->success);
        $this->assertSame(ServiceStatus::Suspended, $active->fresh()->status);
    }

    public function test_staff_suspend_reason_is_limited_before_the_server_is_called(): void
    {
        $service = $this->cpanelService(ServiceStatus::Active, ['username' => 'razacc']);
        $this->signInAdmin();

        $this->post(route('admin.services.module', [$service, 'suspend']), ['reason' => str_repeat('a', 300)])
            ->assertSessionHasErrors('reason');

        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        Http::assertNothingSent();

        $reason = str_repeat('b', 190);
        $this->post(route('admin.services.module', [$service, 'suspend']), ['reason' => $reason])->assertSessionHas('status');

        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);
        $this->assertSame($reason, $service->fresh()->suspension_reason);
        $this->assertLessThanOrEqual(255, mb_strlen((string) ActivityLog::query()->where('action', 'service.suspended')->value('description')));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function cpanelService(ServiceStatus $status, array $attributes = []): Service
    {
        $server = Server::factory()->create(['hostname' => 'whm.example.test']);

        return Service::factory()->for($this->client())->create($attributes + [
            'product_id' => Product::factory()->cpanel($server)->create()->id,
            'server_id' => $server->id,
            'domain' => 'razstudio.com',
            'status' => $status,
        ]);
    }

    private function client(): Client
    {
        return Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'company_name' => null]);
    }
}
