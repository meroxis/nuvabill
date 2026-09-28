<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Client;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_admin_page_loads_for_the_owner(): void
    {
        $admin = $this->signInAdmin();

        $client = Client::factory()->create();
        $server = Server::factory()->create();
        $product = Product::factory()->cpanel($server)->priced(899)->create();
        $order = Order::factory()->create(['client_id' => $client->id]);
        $service = Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->id, 'server_id' => $server->id, 'order_id' => $order->id]);
        $invoice = Invoice::factory()->create(['client_id' => $client->id]);
        $invoice->items()->create(['type' => 'service', 'service_id' => $service->id, 'description' => 'Hosting', 'amount' => 1000]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $ticket->replies()->create(['author_type' => 'client', 'author_id' => $client->id, 'message' => 'Help please']);
        $role = Role::query()->where('name', 'Support')->firstOrFail();

        $pages = [
            route('admin.dashboard'),
            route('admin.clients.index'),
            route('admin.clients.index', ['q' => $client->email]),
            route('admin.clients.show', $client),
            route('admin.clients.create'),
            route('admin.clients.edit', $client),
            route('admin.orders.index'),
            route('admin.orders.show', $order),
            route('admin.services.index'),
            route('admin.services.show', $service),
            route('admin.invoices.index'),
            route('admin.invoices.index', ['status' => 'overdue']),
            route('admin.invoices.show', $invoice),
            route('admin.invoices.create', ['client' => $client->id]),
            route('admin.tickets.index'),
            route('admin.tickets.show', $ticket),
            route('admin.products.index'),
            route('admin.products.create'),
            route('admin.products.edit', $product),
            route('admin.product-groups.create'),
            route('admin.product-groups.edit', $product->group),
            route('admin.servers.index'),
            route('admin.servers.create'),
            route('admin.servers.edit', $server),
            route('admin.settings.edit'),
            route('admin.extensions.index'),
            route('admin.extensions.index', ['tab' => 'gateways']),
            route('admin.settings.gateways.edit', 'stripe'),
            route('admin.settings.gateways.edit', 'paypal'),
            route('admin.settings.gateways.edit', 'banktransfer'),
            route('admin.settings.email-templates.index'),
            route('admin.settings.email-templates.edit', EmailTemplate::query()->firstOrFail()),
            route('admin.settings.departments.index'),
            route('admin.settings.staff.index'),
            route('admin.settings.staff.create'),
            route('admin.settings.staff.edit', $admin),
            route('admin.settings.roles.index'),
            route('admin.settings.roles.create'),
            route('admin.settings.roles.edit', $role),
            route('admin.settings.activity'),
            route('admin.updates.index'),
            route('admin.profile.edit'),
        ];

        foreach ($pages as $url) {
            $this->get($url)->assertOk();
        }

        $this->get(route('admin.invoices.pdf', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_guests_are_sent_to_the_staff_sign_in_page(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.login'))->assertOk()->assertSee('Staff sign in');
    }

    public function test_clients_cannot_open_the_admin_area(): void
    {
        $this->actingAs(Client::factory()->create(), 'web');

        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    }

    public function test_a_role_without_billing_permission_cannot_see_invoices(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['support.manage'])->create());

        $this->get(route('admin.tickets.index'))->assertOk();
        $this->get(route('admin.invoices.index'))->assertForbidden();
        $this->get(route('admin.settings.edit'))->assertForbidden();
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee(route('admin.invoices.index'));
    }

    public function test_billing_manage_includes_billing_view(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['billing.manage'])->create());

        $this->get(route('admin.invoices.index'))->assertOk();
        $this->get(route('admin.invoices.create'))->assertOk();
    }

    public function test_deactivated_staff_are_signed_out(): void
    {
        $this->signInAdmin(Admin::factory()->inactive()->create());

        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    }
}
