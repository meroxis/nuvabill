<?php

namespace Tests\Feature\Servers;

use App\Billing\OrderCanceller;
use App\Enums\ServiceStatus;
use App\Events\ServiceActivated;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\Provisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * An order cancelled while its service is being set up stays cancelled: the finished setup does
 * not make the service active again, where it would run without ever being billed.
 */
class CancelDuringSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Event::fake([ServiceActivated::class]);
    }

    public function test_a_service_cancelled_during_its_setup_stays_cancelled_and_its_new_account_is_removed(): void
    {
        $service = $this->pendingService();
        $this->fakeWhm(whileCreating: fn () => app(OrderCanceller::class)->cancel($service->order, 'Not paid'));

        $result = app(Provisioner::class)->create($service);

        $this->assertFalse($result->success);
        $this->assertSame('The service was cancelled while it was being set up, so its new account was removed again.', $result->message);
        $fresh = $service->fresh();
        $this->assertSame(ServiceStatus::Cancelled, $fresh->status);
        $this->assertNull($fresh->next_due_date);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertNotNull($fresh->username, 'The account details are kept for staff.');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/json-api/removeacct') && $request['username'] === $fresh->username);
        Mail::assertNothingSent();
        Event::assertNotDispatched(ServiceActivated::class);
        $this->assertSame(0, ActivityLog::query()->where('action', 'service.created')->count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'service.account_removed', 'subject_id' => $service->id]);
    }

    public function test_staff_are_told_to_remove_the_account_when_it_cannot_be_removed(): void
    {
        $service = $this->pendingService();
        $this->fakeWhm(
            whileCreating: fn () => Service::query()->whereKey($service->id)->update(['status' => ServiceStatus::Cancelled, 'cancelled_at' => now(), 'next_due_date' => null]),
            removes: false,
        );

        $result = app(Provisioner::class)->create($service);

        $this->assertFalse($result->success);
        $this->assertSame('The service was cancelled while it was being set up. Remove the new account from the server.', $result->message);
        $this->assertSame(ServiceStatus::Cancelled, $service->fresh()->status);
        $this->assertStringStartsWith("Remove the new account of service #{$service->id}", (string) ActivityLog::query()->where('action', 'service.module_failed')->latest('id')->value('description'));
        Mail::assertNothingSent();
    }

    public function test_an_account_is_kept_when_staff_made_the_service_active_by_hand_meanwhile(): void
    {
        $service = $this->pendingService();
        $this->fakeWhm(whileCreating: fn () => Service::query()->whereKey($service->id)->update(['status' => ServiceStatus::Active]));

        $result = app(Provisioner::class)->create($service);

        $this->assertFalse($result->success);
        $this->assertSame('The service was changed while it was being set up, so it was not activated. Check the service.', $result->message);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertNotNull($service->fresh()->username, 'The account details are kept.');
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/json-api/removeacct'));
        Mail::assertNothingSent();
    }

    public function test_a_service_still_pending_when_its_setup_ends_is_activated(): void
    {
        $service = $this->pendingService();
        $this->fakeWhm(whileCreating: fn () => null);

        $this->assertTrue(app(Provisioner::class)->create($service)->success);

        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertFalse($service->isDirty('status'));
        Mail::assertSentCount(1);
        Event::assertDispatched(ServiceActivated::class);
    }

    /**
     * WHM, which runs $whileCreating while it makes the account.
     */
    private function fakeWhm(\Closure $whileCreating, bool $removes = true): void
    {
        Http::fake(function (Request $request) use ($whileCreating, $removes) {
            if (str_ends_with($request->url(), '/json-api/createacct')) {
                $whileCreating();
            }

            $ok = $removes || ! str_ends_with($request->url(), '/json-api/removeacct');

            return Http::response(['metadata' => ['result' => $ok ? 1 : 0, 'reason' => $ok ? 'OK' : 'Account is locked.']]);
        });
    }

    private function pendingService(): Service
    {
        $server = Server::factory()->create(['hostname' => 'whm.example.test']);
        $client = Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'company_name' => null]);
        $order = Order::factory()->for($client)->create();

        return Service::factory()->for($client)->create([
            'order_id' => $order->id,
            'product_id' => Product::factory()->cpanel($server)->create()->id,
            'server_id' => $server->id,
            'domain' => 'razstudio.com',
            'status' => ServiceStatus::Pending,
            'next_due_date' => now()->addMonth(),
        ]);
    }
}
