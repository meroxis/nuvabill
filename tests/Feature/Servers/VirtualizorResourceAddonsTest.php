<?php

namespace Tests\Feature\Servers;

use App\Billing\PaymentRecorder;
use App\Enums\AutoSetup;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceAddon;
use App\Provisioning\Provisioner;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Nuvabill\Extensions\Virtualizor\ResourceAddons;
use stdClass;
use Tests\TestCase;

/**
 * Extra cores, RAM, disk and IPv4 sold as product add-ons on Virtualizor VPS.
 */
class VirtualizorResourceAddonsTest extends TestCase
{
    use RefreshDatabase;

    private ?stdClass $api = null;

    private Server $server;

    private int $clients = 0;

    /**
     * @var array<string, ProductAddon>
     */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = Server::factory()->create(['module' => 'virtualizor', 'hostname' => 'vz.example.test', 'port' => 4085, 'password' => 'S3cretApiPass99']);

        foreach (['cpu' => 'Extra CPU core', 'ram' => 'Extra 2 GB RAM', 'disk' => 'Extra 40 GB disk', 'ipv4' => 'Extra IPv4 address', 'backup' => 'Daily backups'] as $kind => $name) {
            $this->catalog[$kind] = ProductAddon::factory()->create(['name' => $name]);
        }
    }

    public function test_a_vps_without_add_ons_is_made_exactly_as_before_even_with_a_policy(): void
    {
        $api = $this->fakeVirtualizor();
        $service = $this->orderedVps($this->policy(), []);

        $this->assertTrue(app(Provisioner::class)->create($service)->success);

        $this->assertSame(['addvs'], array_column($api->calls, 'kind'));
        $this->assertSame(['addvps', 'hostname', 'node_select', 'num_ips', 'osid', 'plid', 'rootpass', 'user_email', 'user_pass', 'virt'], $this->keys($api->calls[0]['data']));
        $this->assertArrayNotHasKey(ResourceAddons::KEY, $service->fresh()->module_data);
    }

    public function test_add_ons_without_a_policy_change_nothing(): void
    {
        $api = $this->fakeVirtualizor();
        $service = $this->orderedVps([], ['cpu', 'backup']);

        $result = app(Provisioner::class)->create($service);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['addvs'], array_column($api->calls, 'kind'));
        $this->assertSame(1, $api->calls[0]['data']['num_ips']);
        $this->assertArrayNotHasKey('cores', $api->calls[0]['data']);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
    }

    public function test_add_ons_the_policy_does_not_name_change_nothing(): void
    {
        $api = $this->fakeVirtualizor();
        $service = $this->orderedVps($this->policy(), ['backup']);

        $this->assertTrue(app(Provisioner::class)->create($service)->success);
        $this->assertSame(['addvs'], array_column($api->calls, 'kind'));
        $this->assertSame(['addvps', 'hostname', 'node_select', 'num_ips', 'osid', 'plid', 'rootpass', 'user_email', 'user_pass', 'virt'], $this->keys($api->calls[0]['data']));
    }

    public function test_paid_resource_add_ons_raise_the_new_vps_and_are_checked_before_it_is_activated(): void
    {
        $noteBeforeRequest = null;
        $api = $this->fakeVirtualizor([
            'addvs' => function () use (&$noteBeforeRequest): array {
                $noteBeforeRequest = Service::query()->latest('id')->first();

                return ['done' => 1, 'vs_info' => ['vpsid' => '90', 'uid' => '58', 'ips' => ['203.0.113.9', '203.0.113.10']]];
            },
            'vps' => $this->vps(['cores' => '3', 'ram' => '6144', 'ips' => ['1' => '203.0.113.9', '2' => '203.0.113.10']]),
        ]);
        $service = $this->orderedVps($this->policy(), ['cpu', 'ram', 'ipv4', 'backup']);

        $result = app(Provisioner::class)->create($service);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['plans', 'addvs', 'vps'], array_column($api->calls, 'kind'));
        $post = $api->calls[1]['data'];
        $this->assertSame([3, 3, 6144, 2], [$post['plid'], $post['cores'], $post['ram'], $post['num_ips']]);
        $this->assertArrayNotHasKey('space', $post);

        // Noted before the request, with the root password encrypted in the note, not on the service.
        $note = $noteBeforeRequest->module_data[ResourceAddons::KEY];
        $this->assertSame('requested', $note['state']);
        $this->assertSame($service->client->email, $note['email']);
        $this->assertNull($noteBeforeRequest->password);
        $this->assertStringNotContainsString($post['rootpass'], json_encode($noteBeforeRequest->module_data));
        $this->assertSame($post['rootpass'], ResourceAddons::password($noteBeforeRequest));

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame('90', $service->module_data['vpsid']);
        $this->assertSame('58', $service->module_data['uid']);
        $this->assertSame(['203.0.113.9', '203.0.113.10'], $service->module_data['ips']);
        $this->assertSame('verified', $service->module_data[ResourceAddons::KEY]['state']);
        $this->assertArrayNotHasKey('secret', $service->module_data[ResourceAddons::KEY]);
        $this->assertSame($post['rootpass'], $service->password);
    }

    public function test_steps_and_add_ons_of_different_sizes_come_from_the_policy(): void
    {
        $api = $this->fakeVirtualizor(['vps' => $this->vps(['ram' => '9216', 'space' => '100'])]);
        $policy = ['version' => 1, 'ids' => ['ram' => [$this->catalog['ram']->id => 5120], 'disk' => $this->catalog['disk']->id], 'steps' => ['disk' => 20], 'base' => ['ram' => 4096, 'disk' => 80]];
        $service = $this->orderedVps(['resource_addons' => json_encode($policy)], ['ram', 'disk'], config: ['plan_id' => null]);

        $result = app(Provisioner::class)->create($service);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['addvs', 'vps'], array_column($api->calls, 'kind'));
        $this->assertSame(9216, $api->calls[0]['data']['ram']);
        $this->assertSame([['size' => 100]], $api->calls[0]['data']['space']);
    }

    public function test_unpaid_or_unclear_orders_stop_the_create_before_anything_is_sent(): void
    {
        $api = $this->fakeVirtualizor();

        $unpaid = $this->orderedVps($this->policy(), ['cpu'], paid: false);
        $this->assertSame('The order invoice with the resource add-ons is not paid yet, so no VPS was made.', app(Provisioner::class)->create($unpaid)->message);

        $missingLine = $this->orderedVps($this->policy(), ['cpu'], lines: false);
        $this->assertSame('A resource add-on is not on the paid order invoice, so no VPS was made. Check the order.', app(Provisioner::class)->create($missingLine)->message);

        $credited = $this->orderedVps($this->policy(), ['cpu']);
        $credited->order->invoice->creditNotes()->create(['number' => 'CN-1', 'client_id' => $credited->client_id, 'currency' => 'USD', 'subtotal' => 100, 'tax' => 0, 'total' => 100, 'items' => [], 'method' => 'none', 'reason' => 'Goodwill', 'issued_at' => now()]);
        $this->assertStringContainsString('credit note', app(Provisioner::class)->create($credited)->message);

        $this->assertSame([], $api->calls);
        $this->assertSame(ServiceStatus::Pending, $unpaid->fresh()->status);
    }

    public function test_an_invalid_policy_stops_only_a_vps_with_add_ons(): void
    {
        $api = $this->fakeVirtualizor();

        foreach (['{"version":1,"ids":{"gpu":5}}', '{"version":2,"ids":{"cpu":5}}', '{"version":1,"ids":{"cpu":"five"}}', 'not json', '{"version":1,"ids":{"cpu":5},"extra":1}'] as $policy) {
            $service = $this->orderedVps(['resource_addons' => $policy], ['backup']);
            $result = app(Provisioner::class)->create($service);

            $this->assertFalse($result->success, $policy);
            $this->assertStringStartsWith('The resource add-on policy of this product is not valid', $result->message);
        }

        $this->assertSame([], $api->calls);

        $plain = $this->orderedVps(['resource_addons' => 'not json'], []);
        $this->assertTrue(app(Provisioner::class)->create($plain)->success);
    }

    public function test_a_create_without_a_clear_answer_is_never_sent_twice(): void
    {
        $api = $this->fakeVirtualizor(['addvs' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
        $service = $this->orderedVps($this->policy(), ['cpu']);

        $first = app(Provisioner::class)->create($service);
        $rootPassword = $api->calls[1]['data']['rootpass'];

        $this->assertFalse($first->success);
        $this->assertStringContainsString('no clear answer', $first->message);
        $this->assertSame('requested', $service->fresh()->module_data[ResourceAddons::KEY]['state']);
        // The pending service shows no root password; the note keeps it for a VPS found later.
        $this->assertNull($service->fresh()->password);
        $this->assertSame($rootPassword, ResourceAddons::password($service->fresh()));

        // Virtualizor lists another VPS with a similar name: still unclear, so nothing is sent.
        $api = $this->fakeVirtualizor(['byname' => ['vs' => ['12' => ['vpsid' => '12', 'hostname' => 'vps1.example.com', 'email' => 'someone@example.net']]]]);
        $this->assertFalse(app(Provisioner::class)->create($service->fresh())->success);
        $this->assertSame(['byname'], array_column($api->calls, 'kind'));

        // Nothing is listed yet, but the request was only just sent.
        $api = $this->fakeVirtualizor(['byname' => ['vs' => []]]);
        $this->assertFalse(app(Provisioner::class)->create($service->fresh())->success);
        $this->assertSame(['byname'], array_column($api->calls, 'kind'));
        $this->assertSame(['act' => 'vs', 'search' => '1', 'vpshostname' => 'vps1.example.com', 'page' => '1', 'reslen' => '50'], $this->withoutCredentials($api->calls[0]['query']));

        // The VPS it made shows up: it is taken and checked, not made again.
        $api = $this->fakeVirtualizor([
            'byname' => ['vs' => ['90' => ['vpsid' => '90', 'hostname' => 'VPS1.example.com', 'email' => 'mer.las@example.com']]],
            'vps' => $this->vps(['cores' => '3']),
        ]);
        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['byname', 'vps'], array_column($api->calls, 'kind'));
        $this->assertSame('90', $service->fresh()->module_data['vpsid']);
        $this->assertSame($rootPassword, $service->fresh()->password);
    }

    public function test_a_vps_found_after_an_unclear_create_is_matched_by_the_email_it_was_made_with(): void
    {
        $this->fakeVirtualizor(['addvs' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
        $service = $this->orderedVps($this->policy(), ['cpu']);
        $this->assertFalse(app(Provisioner::class)->create($service)->success);

        // The client changed their email since, and another service already uses VPS 12 of that name.
        $service->client->update(['email' => 'new.address@example.org']);
        $other = $this->orderedVps([], []);
        $other->update(['status' => ServiceStatus::Active, 'module_data' => ['vpsid' => '12']]);
        $api = $this->fakeVirtualizor([
            'byname' => ['vs' => [
                '12' => ['vpsid' => '12', 'hostname' => 'vps1.example.com', 'email' => 'mer.las@example.com'],
                '90' => ['vpsid' => '90', 'hostname' => 'vps1.example.com', 'email' => 'mer.las@example.com'],
            ]],
            'vps' => $this->vps(['cores' => '3']),
        ]);

        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['byname', 'vps'], array_column($api->calls, 'kind'));
        $this->assertSame('90', $service->fresh()->module_data['vpsid']);
    }

    public function test_another_users_vps_with_the_same_name_does_not_block_a_new_create_for_ever(): void
    {
        $this->fakeVirtualizor(['addvs' => fn () => throw new ConnectionException('cURL error 7: refused')]);
        $service = $this->orderedVps($this->policy(), ['cpu']);
        $this->assertFalse(app(Provisioner::class)->create($service)->success);
        $someoneElse = ['vs' => ['12' => ['vpsid' => '12', 'hostname' => 'vps1.example.com', 'email' => 'someone@example.net']]];

        // A search that did not work (other names, or no email) never counts as "none was made".
        $this->travel(11)->minutes();

        foreach ([
            ['vs' => ['12' => ['vpsid' => '12', 'hostname' => 'other.example.net', 'email' => 'someone@example.net']]],
            ['vs' => ['12' => ['vpsid' => '12', 'hostname' => 'vps1.example.com']]],
        ] as $answer) {
            $api = $this->fakeVirtualizor(['byname' => $answer]);
            $this->assertStringContainsString('no clear answer', app(Provisioner::class)->create($service->fresh())->message);
            $this->assertSame(['byname'], array_column($api->calls, 'kind'));
        }

        $api = $this->fakeVirtualizor(['byname' => $someoneElse, 'vps' => $this->vps(['cores' => '3'])]);
        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['byname', 'plans', 'addvs', 'vps'], array_column($api->calls, 'kind'));
    }

    public function test_a_create_that_virtualizor_shows_never_ran_may_be_sent_again_later(): void
    {
        $this->fakeVirtualizor(['addvs' => fn () => throw new ConnectionException('cURL error 7: refused')]);
        $service = $this->orderedVps($this->policy(), ['cpu']);
        $this->assertFalse(app(Provisioner::class)->create($service)->success);

        $this->travel(11)->minutes();
        $api = $this->fakeVirtualizor(['byname' => ['vs' => []], 'vps' => $this->vps(['cores' => '3'])]);

        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['byname', 'plans', 'addvs', 'vps'], array_column($api->calls, 'kind'));
    }

    public function test_an_unclear_create_stays_on_its_server_when_that_server_is_turned_off(): void
    {
        $this->fakeVirtualizor(['addvs' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
        $service = $this->orderedVps($this->policy(), ['cpu']);
        $this->assertFalse(app(Provisioner::class)->create($service)->success);
        $this->assertSame($this->server->id, $service->fresh()->module_data[ResourceAddons::KEY]['server_id']);

        // Staff turn the server off and add another one.
        $this->server->update(['is_active' => false]);
        $other = Server::factory()->create(['module' => 'virtualizor', 'hostname' => 'vz2.example.test', 'port' => 4085]);
        $this->travel(11)->minutes();

        // The VPS the request made is found on the first server, and taken.
        $api = $this->fakeVirtualizor([
            'byname' => ['vs' => ['90' => ['vpsid' => '90', 'hostname' => 'vps1.example.com', 'email' => 'mer.las@example.com']]],
            'vps' => $this->vps(['cores' => '3']),
        ]);
        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['byname', 'vps'], array_column($api->calls, 'kind'));
        $this->assertSame($this->server->id, $service->fresh()->server_id);
        $this->assertNotSame($other->id, $service->fresh()->server_id);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'vz2.example.test'));
    }

    public function test_a_create_that_made_no_vps_on_a_server_turned_off_moves_on_only_at_the_next_try(): void
    {
        $this->fakeVirtualizor(['addvs' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
        $service = $this->orderedVps($this->policy(), ['cpu']);
        $this->assertFalse(app(Provisioner::class)->create($service)->success);
        $this->server->update(['is_active' => false]);
        $other = Server::factory()->create(['module' => 'virtualizor', 'hostname' => 'vz2.example.test', 'port' => 4085]);
        $this->travel(11)->minutes();

        // Virtualizor shows none was made on the first server: no new VPS is sent to it.
        $api = $this->fakeVirtualizor(['byname' => ['vs' => []]]);
        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertSame('Virtualizor shows the earlier create request made no VPS, and this server is turned off. Try again: the next try makes the VPS on a server that is on.', $result->message);
        $this->assertSame(['byname'], array_column($api->calls, 'kind'));
        $this->assertArrayNotHasKey(ResourceAddons::KEY, (array) $service->fresh()->module_data);

        // The next try picks the server that is on.
        $api = $this->fakeVirtualizor(['vps' => $this->vps(['cores' => '3'])]);
        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['plans', 'addvs', 'vps'], array_column($api->calls, 'kind'));
        $this->assertSame($other->id, $service->fresh()->server_id);
        $this->assertStringContainsString('vz2.example.test', $api->calls[1]['url']);
    }

    public function test_an_unclear_create_is_not_sent_again_after_the_service_was_moved_by_hand(): void
    {
        $this->fakeVirtualizor(['addvs' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
        $service = $this->orderedVps($this->policy(), ['cpu']);
        $this->assertFalse(app(Provisioner::class)->create($service)->success);

        $other = Server::factory()->create(['module' => 'virtualizor', 'hostname' => 'vz2.example.test', 'port' => 4085]);
        $service->update(['server_id' => $other->id]);
        $this->travel(11)->minutes();
        $api = $this->fakeVirtualizor(['byname' => ['vs' => []]]);

        $result = app(Provisioner::class)->create($service->fresh());

        $this->assertFalse($result->success);
        $this->assertSame("The create request for this VPS was sent to server #{$this->server->id}, so a VPS it made is only looked for there. Move the service back to that server and try again. No VPS is made on another server.", $result->message);
        $this->assertSame([], $api->calls);
        $this->assertSame('requested', $service->fresh()->module_data[ResourceAddons::KEY]['state']);
    }

    public function test_a_vps_set_up_as_soon_as_the_order_is_placed_is_made_once_its_order_invoice_is_paid(): void
    {
        $api = $this->fakeVirtualizor(['vps' => $this->vps(['cores' => '3'])]);
        $service = $this->orderedVps($this->policy(), ['cpu'], paid: false);
        $service->product->update(['auto_setup' => AutoSetup::OnOrder]);
        $invoice = $service->order->invoice;
        InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'service_id' => $service->id, 'type' => InvoiceItem::TYPE_SERVICE, 'description' => 'VPS - vps1.example.com', 'amount' => 2500, 'period_start' => today(), 'period_end' => today()->addMonth()->subDay()]);

        // The setup when the order was placed could not make the VPS yet.
        $this->assertSame('The order invoice with the resource add-ons is not paid yet, so no VPS was made.', app(Provisioner::class)->create($service)->message);
        $this->assertSame([], $api->calls);

        app(PaymentRecorder::class)->record($invoice->fresh(), 3000, 'banktransfer', 'BANK-1');

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertSame(['plans', 'addvs', 'vps'], array_column($api->calls, 'kind'));
    }

    public function test_a_create_virtualizor_refused_may_be_tried_again(): void
    {
        $api = $this->fakeVirtualizor(['addvs' => ['error' => ['No free IP in pool 5']]]);
        $service = $this->orderedVps($this->policy(), ['cpu']);

        $result = app(Provisioner::class)->create($service);

        $this->assertSame('Virtualizor refused to create the VPS, so none was made. Check the plan, the OS template and free IP addresses in Virtualizor, then try again.', $result->message);
        $this->assertArrayNotHasKey(ResourceAddons::KEY, (array) $service->fresh()->module_data);

        $api = $this->fakeVirtualizor(['vps' => $this->vps(['cores' => '3'])]);
        $this->assertTrue(app(Provisioner::class)->create($service->fresh())->success);
        $this->assertContains('addvs', array_column($api->calls, 'kind'));
    }

    public function test_a_vps_that_does_not_match_the_paid_resources_stays_pending_and_is_not_made_again(): void
    {
        $this->fakeVirtualizor(['vps' => $this->vps(['cores' => '2'])]);
        $service = $this->orderedVps($this->policy(), ['cpu']);

        $result = app(Provisioner::class)->create($service);

        $this->assertFalse($result->success);
        $this->assertSame('VPS #90 does not match the paid add-on resources (CPU cores). It stays pending: fix it in Virtualizor, then try again. No second VPS is made. If you cancel the order instead, delete VPS #90 in Virtualizor.', $result->message);
        $service->refresh();
        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertSame('90', $service->module_data['vpsid']);
        // The client sees no root password while the VPS waits.
        $this->assertNull($service->password);
        $this->actingAs($service->client, 'web')->get(route('client.services.show', $service))->assertOk()->assertDontSee(ResourceAddons::password($service));

        // A VPS that could not be checked tells staff the same.
        $this->fakeVirtualizor(['vps' => fn () => Http::response('Bad gateway', 502)]);
        $this->assertSame('VPS #90 was made but could not be checked yet. Try again; no second VPS is made. If you cancel the order instead, delete VPS #90 in Virtualizor.', app(Provisioner::class)->create($service)->message);

        $api = $this->fakeVirtualizor(['vps' => $this->vps(['cores' => '3'])]);
        $this->assertTrue(app(Provisioner::class)->create($service)->success);
        $this->assertSame(['vps'], array_column($api->calls, 'kind'));
        $this->assertNotNull($service->fresh()->password);
    }

    public function test_a_create_virtualizor_refused_leaves_no_root_password_on_the_waiting_service(): void
    {
        $this->fakeVirtualizor(['addvs' => ['error' => ['No free IP in pool 5']]]);
        $service = $this->orderedVps($this->policy(), ['cpu']);
        // An older version put the password on the service before it sent the request.
        $service->update(['password' => 'Legacy12345']);

        $this->assertFalse(app(Provisioner::class)->create($service)->success);

        $this->assertNull($service->fresh()->password);
        $this->assertArrayNotHasKey(ResourceAddons::KEY, (array) $service->fresh()->module_data);
    }

    public function test_policies_and_notes_from_before_the_update_keep_working(): void
    {
        $policy = [
            'version' => 1,
            'server_id' => $this->server->id,
            'public_pool_id' => 5,
            'plan_id' => 3,
            'base' => ['cores' => 2, 'ram' => 4096, 'disk' => 80, 'num_ips' => 1, 'bandwidth' => 2000],
            'ids' => ['cpu' => $this->catalog['cpu']->id, 'ram' => $this->catalog['ram']->id, 'disk' => $this->catalog['disk']->id, 'ipv4' => $this->catalog['ipv4']->id],
        ];
        $api = $this->fakeVirtualizor([
            'vps' => $this->vps(['cores' => '3', 'ram' => '6144', 'space' => '120', 'ips' => ['1' => '203.0.113.9', '2' => '203.0.113.10']]),
            'ips' => fn (array $query): array => ['ips' => ['7' => ['ip' => $query['ipsearch'], 'ippid' => '5', 'vpsid' => '90', 'ipv6' => '0', 'internal' => '0', 'nat' => '0']]],
        ]);
        $service = $this->orderedVps(['resource_addons' => json_encode($policy)], ['cpu', 'ram', 'disk', 'ipv4']);

        $result = app(Provisioner::class)->create($service);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(['plans', 'addvs', 'vps', 'ips', 'ips'], array_column($api->calls, 'kind'));
        $post = $api->calls[1]['data'];
        $this->assertSame([3, 6144, [['size' => 120]], 2], [$post['cores'], $post['ram'], $post['space'], $post['num_ips']]);

        // A plan that changed in Virtualizor since the policy was written stops the create.
        $api = $this->fakeVirtualizor(['plans' => ['plans' => ['3' => $this->plan(['ram' => '8192'])]]]);
        $drifted = $this->orderedVps(['resource_addons' => json_encode($policy)], ['cpu']);
        $this->assertStringContainsString('no longer matches', app(Provisioner::class)->create($drifted)->message);
        $this->assertSame(['plans'], array_column($api->calls, 'kind'));

        // A create the old version sent without a clear answer is not sent again.
        $api = $this->fakeVirtualizor(['byname' => ['vs' => ['5' => ['vpsid' => '5', 'hostname' => 'other.example.com', 'email' => 'raz@example.com']]]]);
        $old = $this->orderedVps(['resource_addons' => json_encode($policy)], ['cpu']);
        $old->update(['module_data' => [ResourceAddons::KEY => ['snapshot' => ['version' => 1, 'totals' => ['cores' => 3]], 'state' => ['status' => 'requested', 'vpsid' => null, 'hostname' => 'vps1.example.com'], 'signature' => 'abc']]]);

        $this->assertStringContainsString('no clear answer', app(Provisioner::class)->create($old->fresh())->message);
        $this->assertSame(['byname'], array_column($api->calls, 'kind'));
    }

    public function test_a_plan_change_without_extras_is_one_call_as_before(): void
    {
        $api = $this->fakeVirtualizor(['managevps' => ['done' => ['done' => true], 'vs_info' => ['vpsid' => '77']]]);
        $service = $this->activeVps([], ['backup']);

        $this->assertTrue(app(Provisioner::class)->changePackage($service)->success);

        $this->assertSame(['managevps'], array_column($api->calls, 'kind'));
        $this->assertSame(['editvps' => 1, 'plid' => 4, 'apply_plan' => 1], $api->calls[0]['data']);
    }

    public function test_a_plan_change_sets_the_extras_again_on_top_of_the_new_plan(): void
    {
        $vps = ['cores' => '3', 'ram' => '6144'];
        $api = $this->fakeVirtualizor([
            'plans' => ['plans' => ['4' => $this->plan(['plid' => '4', 'cores' => '4', 'ram' => '8192'])]],
            'vps' => function (array $query) use (&$vps): array {
                return ['vs' => ['77' => $vps + ['vpsid' => '77', 'uid' => '58', 'suspended' => '0', 'virt' => 'kvm', 'ips' => ['1' => '203.0.113.7']]]];
            },
            'managevps' => function (array $query, array $data) use (&$vps): array {
                if (isset($data['apply_plan'])) {
                    $vps = ['cores' => '4', 'ram' => '8192'];
                } else {
                    $vps = ['cores' => (string) ($data['cores'] ?? $vps['cores']), 'ram' => (string) ($data['ram'] ?? $vps['ram'])];
                }

                return ['done' => ['done' => true], 'vs_info' => ['vpsid' => '77']];
            },
        ]);
        $service = $this->activeVps($this->policy(), ['cpu', 'ram', 'backup']);

        $result = app(Provisioner::class)->changePackage($service);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame('Plan applied to the VPS, with its add-on extras.', $result->message);
        $this->assertSame(['plans', 'vps', 'managevps', 'vps', 'managevps', 'vps'], array_column($api->calls, 'kind'));
        $this->assertSame(['editvps' => 1, 'plid' => 4, 'apply_plan' => 1], $api->calls[2]['data']);
        $this->assertSame(['editvps' => 1, 'cores' => 5, 'ram' => 10240], $api->calls[4]['data']);
    }

    public function test_a_plan_change_tells_staff_which_extras_to_set_by_hand(): void
    {
        $this->fakeVirtualizor([
            'plans' => ['plans' => ['4' => $this->plan(['plid' => '4', 'cores' => '4'])]],
            'vps' => $this->vps(['cores' => '4'], '77'),
            'managevps' => ['done' => ['done' => true], 'vs_info' => ['vpsid' => '77']],
        ]);
        $service = $this->activeVps($this->policy(), ['cpu']);

        $result = app(Provisioner::class)->changePackage($service);

        $this->assertFalse($result->success);
        $this->assertSame('The new plan is applied, but its add-on extras could not all be set again (CPU cores). Set them in Virtualizor by hand: CPU cores 5.', $result->message);
    }

    public function test_a_plan_change_is_refused_when_the_new_plan_does_not_cover_the_extras(): void
    {
        $api = $this->fakeVirtualizor();
        $service = $this->activeVps([], ['cpu']);
        $service->update(['module_data' => $service->module_data + [ResourceAddons::KEY => ['state' => 'verified', 'vpsid' => '77', 'hostname' => 'vps1.example.com', 'totals' => ['cores' => 3], 'addons' => [$this->catalog['cpu']->id]]]]);

        $result = app(Provisioner::class)->changePackage($service->fresh());

        $this->assertFalse($result->success);
        $this->assertStringContainsString('the plan was not applied on the server', $result->message);
        $this->assertSame([], $api->calls);
    }

    /**
     * The generic policy: one of each resource add-on from the catalog.
     *
     * @return array<string, string>
     */
    private function policy(): array
    {
        return ['resource_addons' => json_encode(['version' => 1, 'ids' => [
            'cpu' => $this->catalog['cpu']->id,
            'ram' => $this->catalog['ram']->id,
            'disk' => $this->catalog['disk']->id,
            'ipv4' => $this->catalog['ipv4']->id,
        ]])];
    }

    /**
     * A pending VPS of Mer Las, ordered with the given add-ons on an order invoice.
     *
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $addons
     * @param  array<string, mixed>  $config
     */
    private function orderedVps(array $settings, array $addons, bool $paid = true, bool $lines = true, array $config = []): Service
    {
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'company_name' => null, 'email' => $this->clients++ === 0 ? 'mer.las@example.com' : 'mer.las'.$this->clients.'@example.com']);
        $product = Product::factory()->create(['server_module' => 'virtualizor', 'server_id' => $this->server->id, 'module_config' => array_filter($config + $settings + ['virt' => 'kvm', 'os_id' => '100', 'plan_id' => '3'], fn (mixed $value): bool => $value !== null)]);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'total' => 3000, 'subtotal' => 3000] + ($paid ? ['status' => InvoiceStatus::Paid, 'amount_paid' => 3000, 'paid_at' => now()] : []));
        $order = Order::factory()->create(['client_id' => $client->id, 'invoice_id' => $invoice->id, 'status' => OrderStatus::Active]);
        $service = Service::factory()->create([
            'client_id' => $client->id,
            'product_id' => $product->id,
            'server_id' => $this->server->id,
            'order_id' => $order->id,
            'status' => ServiceStatus::Pending,
            'domain' => 'vps1.example.com',
            'module_data' => null,
            'registration_date' => today(),
        ]);

        foreach ($addons as $kind) {
            $addon = $this->catalog[$kind];
            $service->addons()->create(['product_addon_id' => $addon->id, 'name' => $addon->name, 'recurring_amount' => 500, 'status' => ServiceAddon::STATUS_ACTIVE]);

            if ($lines) {
                InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'service_id' => $service->id, 'type' => InvoiceItem::TYPE_ADDON, 'description' => $addon->name.' - vps1.example.com', 'amount' => 500, 'period_start' => today(), 'period_end' => today()->addMonth()->subDay()]);
            }
        }

        return $service;
    }

    /**
     * An active VPS 77 that moved to a product on plan 4.
     *
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $addons
     */
    private function activeVps(array $settings, array $addons): Service
    {
        $service = $this->orderedVps($settings, $addons, config: ['plan_id' => '4']);
        $service->update(['status' => ServiceStatus::Active, 'module_data' => ['vpsid' => '77', 'uid' => '58']]);

        return $service->fresh();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function plan(array $values = []): array
    {
        return $values + ['plid' => '3', 'virt' => 'kvm', 'ram' => '4096', 'space' => '80', 'cores' => '2', 'bandwidth' => '2000', 'ips' => '1', 'ippoolid' => 'a:1:{i:0;s:1:"5";}'];
    }

    /**
     * The answer for one VPS in Virtualizor's list.
     *
     * @param  array<string, mixed>  $values
     */
    private function vps(array $values, string $id = '90'): Closure
    {
        return fn (array $query): array => ['vs' => [$id => $values + [
            'vpsid' => $id,
            'uid' => '58',
            'suspended' => '0',
            'virt' => 'kvm',
            'hostname' => 'vps1.example.com',
            'email' => 'mer.las@example.com',
            'cores' => '2',
            'ram' => '4096',
            'space' => '80',
            'bandwidth' => '2000',
            'ips' => ['1' => '203.0.113.9'],
        ]]];
    }

    /**
     * A fake Virtualizor answering by request kind; a second call swaps the answers and clears the log.
     *
     * @param  array<string, mixed>  $answers
     */
    private function fakeVirtualizor(array $answers = []): stdClass
    {
        $answers += [
            'addvs' => ['done' => 1, 'vs_info' => ['vpsid' => '90', 'uid' => '58', 'ips' => ['203.0.113.9']]],
            'plans' => ['plans' => ['3' => $this->plan()]],
            'vps' => $this->vps([]),
        ];

        if ($this->api === null) {
            $this->api = new stdClass;

            Http::fake(function (Request $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $act = (string) ($query['act'] ?? '');
                $kind = match (true) {
                    $act !== 'vs' => $act,
                    isset($query['vpshostname']) => 'byname',
                    isset($query['vpsid']) => 'vps',
                    default => 'vs',
                };
                $this->api->calls[] = ['kind' => $kind, 'query' => $query, 'data' => $request->data(), 'url' => $request->url()];
                $answer = $this->api->answers[$kind] ?? ['error' => ['Unexpected request: '.$kind]];
                $answer = $answer instanceof Closure ? $answer($query, $request->data()) : $answer;

                return is_array($answer) ? Http::response($answer) : $answer;
            });
        }

        $this->api->answers = $answers;
        $this->api->calls = [];

        return $this->api;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function keys(array $data): array
    {
        $keys = array_keys($data);
        sort($keys);

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function withoutCredentials(array $query): array
    {
        return array_diff_key($query, array_flip(['api', 'adminapikey', 'adminapipass']));
    }
}
