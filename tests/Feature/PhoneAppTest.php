<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Events\InvoicePaid;
use App\Events\OrderPlaced;
use App\Events\TicketReplied;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PushSubscription;
use App\Models\Ticket;
use App\Push\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhoneAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_phones_can_install_the_admin_area_as_an_app(): void
    {
        $manifest = $this->get(route('admin.manifest'))->assertOk()->assertHeader('Content-Type', 'application/manifest+json');
        $manifest->assertJson(['start_url' => '/admin/today', 'scope' => '/admin/', 'display' => 'standalone']);
        $this->assertCount(3, $manifest->json('icons'));

        $worker = $this->get(route('admin.service-worker'))->assertOk()->assertHeader('Service-Worker-Allowed', '/admin/');
        $this->assertStringContainsString('application/javascript', (string) $worker->headers->get('Content-Type'));
        $this->assertStringContainsString("addEventListener('push'", (string) $worker->getContent());

        $this->signInAdmin();
        $this->get(route('admin.today'))->assertOk()->assertSee('rel="manifest"', false)->assertSee('data-admin-app=', false);
    }

    public function test_today_shows_the_numbers_and_what_needs_the_staff_member(): void
    {
        $this->signInAdmin();
        $client = Client::factory()->create(['first_name' => 'Raz', 'last_name' => '']);
        Ticket::factory()->create(['client_id' => $client->id, 'subject' => 'Error after moving', 'status' => TicketStatus::CustomerReply, 'last_reply_at' => now()->subHours(5)]);
        $order = Order::factory()->create(['client_id' => $client->id, 'needs_review' => true, 'total' => 18600]);

        $this->get(route('admin.today'))->assertOk()
            ->assertSee('New orders')
            ->assertSee('1 waiting over 3 hours')
            ->assertSee('Error after moving')
            ->assertSee('Held by the fraud check')
            ->assertSee(route('admin.orders.accept', $order), false)
            ->assertSee('Turn on alerts on this device');
    }

    public function test_staff_only_see_numbers_their_role_allows(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['support.manage'])->create());

        $this->get(route('admin.today'))->assertOk()->assertSee('Open tickets')->assertDontSee('New orders')->assertDontSee('Overdue');
    }

    public function test_a_phone_turns_alerts_on_and_off(): void
    {
        $admin = $this->signInAdmin();
        $keys = ['p256dh' => WebPush::encode("\x04".random_bytes(64)), 'auth' => WebPush::encode(random_bytes(16))];

        // Only real push services: the server never posts to an address staff choose.
        $this->postJson(route('admin.profile.push.store'), ['endpoint' => 'https://127.0.0.1/steal', 'keys' => $keys])->assertStatus(422);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1')
            ->postJson(route('admin.profile.push.store'), ['endpoint' => 'https://web.push.apple.com/QGx', 'keys' => $keys])->assertOk();

        $subscription = PushSubscription::query()->sole();
        $this->assertSame($admin->id, $subscription->admin_id);
        $this->assertSame('iPhone · Safari', $subscription->device);
        $this->get(route('admin.profile.edit'))->assertOk()->assertSee('iPhone · Safari');

        $other = Admin::factory()->create();
        $theirs = PushSubscription::query()->create(['admin_id' => $other->id, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/x', 'endpoint_hash' => PushSubscription::hashOf('https://fcm.googleapis.com/fcm/send/x'), 'public_key' => $keys['p256dh'], 'auth_token' => $keys['auth']]);
        $this->delete(route('admin.profile.push.destroy', $theirs))->assertNotFound();

        $this->deleteJson(route('admin.profile.push.forget'), ['endpoint' => 'https://web.push.apple.com/QGx'])->assertOk();
        $this->assertSame(0, $admin->pushSubscriptions()->count());
    }

    public function test_staff_choose_their_alerts(): void
    {
        $admin = $this->signInAdmin();

        $this->put(route('admin.profile.push.alerts'), ['orders' => '1', 'payments' => '1', 'payments_over' => '50', 'tickets' => '0', 'replies' => '1'])->assertSessionHas('status');

        $this->assertSame(['orders' => true, 'payments' => true, 'payments_over' => 5000, 'tickets' => false, 'replies' => true], $admin->fresh()->pushAlerts());
    }

    public function test_a_new_order_alerts_the_phones_of_staff_who_handle_orders(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        $orders = Admin::factory()->withPermissions(['orders.manage'])->create();
        $support = Admin::factory()->withPermissions(['support.manage'])->create();
        $this->subscribe($orders, 'orders');
        $this->subscribe($support, 'support');
        $order = Order::factory()->create(['total' => 4900]);

        event(new OrderPlaced($order));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://fcm.googleapis.com/fcm/send/orders'
            && $request->header('Content-Encoding')[0] === 'aes128gcm'
            && str_starts_with($request->header('Authorization')[0], 'vapid t=')
            && strlen($request->body()) > 86);
        $this->assertNotNull(PushSubscription::query()->where('admin_id', $orders->id)->value('last_sent_at'));
    }

    public function test_payments_below_the_chosen_amount_do_not_alert(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        $admin = Admin::factory()->withPermissions(['billing.view'])->create(['push_alerts' => ['payments' => true, 'payments_over' => 5000]]);
        $this->subscribe($admin, 'billing');

        event(new InvoicePaid(Invoice::factory()->create(['total' => 1500, 'currency' => (string) setting('billing.currency')])));
        Http::assertNothingSent();

        event(new InvoicePaid(Invoice::factory()->create(['total' => 9900, 'currency' => (string) setting('billing.currency')])));
        Http::assertSentCount(1);
    }

    public function test_a_reply_on_an_assigned_ticket_only_alerts_the_assignee(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        $assignee = Admin::factory()->withPermissions(['support.manage'])->create();
        $colleague = Admin::factory()->withPermissions(['support.manage'])->create();
        $this->subscribe($assignee, 'assignee');
        $this->subscribe($colleague, 'colleague');
        $ticket = Ticket::factory()->create(['assigned_admin_id' => $assignee->id]);
        $reply = $ticket->replies()->create(['author_type' => 'client', 'author_id' => $ticket->client_id, 'message' => 'Still broken']);

        event(new TicketReplied($ticket, $reply));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/assignee'));
    }

    public function test_a_phone_the_push_service_forgot_is_removed(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 410)]);
        $admin = $this->signInAdmin();
        $this->subscribe($admin, 'gone');

        $this->post(route('admin.profile.push.test'))->assertSessionHas('error');

        $this->assertSame(0, PushSubscription::query()->count());
    }

    private function subscribe(Admin $admin, string $name): void
    {
        $options = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $key = openssl_pkey_new($options) ?: openssl_pkey_new($options + ['config' => resource_path('openssl.cnf')]);
        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.$name;

        PushSubscription::query()->create([
            'admin_id' => $admin->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashOf($endpoint),
            'public_key' => WebPush::encode(WebPush::rawPublicKey($key)),
            'auth_token' => WebPush::encode(random_bytes(16)),
        ]);
    }
}
