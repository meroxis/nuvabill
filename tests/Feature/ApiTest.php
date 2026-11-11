<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Models\Admin;
use App\Models\ApiToken;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_requests_without_a_valid_key_are_refused(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer nb_wrong'])->assertUnauthorized();

        [, $plain] = ApiToken::issue(Admin::factory()->inactive()->create(), 'Old', true);
        $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$plain}"])->assertUnauthorized();
    }

    public function test_a_key_reads_as_its_staff_member_and_follows_the_role(): void
    {
        $admin = Admin::factory()->withPermissions(['clients.view'])->create(['name' => 'Raz']);
        [$token, $plain] = ApiToken::issue($admin, 'Reports', false);
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las']);
        $headers = ['Authorization' => "Bearer {$plain}"];

        $this->getJson('/api/v1/me', $headers)->assertOk()->assertJsonPath('staff.name', 'Raz')->assertJsonPath('key.can_write', false);
        $this->getJson('/api/v1/clients', $headers)->assertOk()->assertJsonPath('data.0.first_name', 'Mer')->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/clients/{$client->id}", $headers)->assertOk()->assertJsonPath('data.email', $client->email);

        $this->getJson('/api/v1/invoices', $headers)->assertForbidden();
        $this->assertNotNull($token->fresh()->last_used_at);
    }

    public function test_the_product_list_shows_products_the_store_does_not_list_only_to_staff_who_manage_products(): void
    {
        Product::factory()->priced(899)->create(['name' => 'Business Plan']);
        Product::factory()->priced(100)->create(['name' => 'Private Plan', 'is_visible' => false]);
        Product::factory()->priced(500)->create(['name' => 'Legacy Plan', 'product_group_id' => ProductGroup::factory()->create(['is_visible' => false])]);

        [, $plain] = ApiToken::issue(Admin::factory()->withPermissions(['clients.view'])->create(['name' => 'Raz']), 'Reports', false);
        $names = $this->getJson('/api/v1/products', ['Authorization' => "Bearer {$plain}"])->assertOk()->json('data.*.name');
        $this->assertSame(['Business Plan'], $names);

        [, $plain] = ApiToken::issue(Admin::factory()->withPermissions(['products.manage'])->create(['name' => 'Las']), 'Catalog', false);
        $names = $this->getJson('/api/v1/products', ['Authorization' => "Bearer {$plain}"])->assertOk()->json('data.*.name');
        $this->assertEqualsCanonicalizing(['Business Plan', 'Private Plan', 'Legacy Plan'], $names);
    }

    public function test_a_read_only_key_cannot_change_data(): void
    {
        [, $plain] = ApiToken::issue(Admin::factory()->create(), 'Reports', false);

        $this->postJson('/api/v1/clients', ['first_name' => 'Mer', 'last_name' => 'Las', 'email' => 'mer@example.test'], ['Authorization' => "Bearer {$plain}"])
            ->assertForbidden();
        $this->assertSame(0, Client::query()->count());
    }

    public function test_a_key_creates_a_client_and_an_invoice_and_records_a_payment(): void
    {
        Mail::fake();
        [, $plain] = ApiToken::issue(Admin::factory()->create(), 'Accounting', true);
        $headers = ['Authorization' => "Bearer {$plain}"];

        $clientId = $this->postJson('/api/v1/clients', ['first_name' => 'Mer', 'last_name' => 'Las', 'email' => 'mer@example.test', 'country' => 'GB'], $headers)
            ->assertCreated()
            ->json('data.id');

        $response = $this->postJson('/api/v1/invoices', [
            'client_id' => $clientId,
            'items' => [['description' => 'Setup work', 'amount' => 2500], ['description' => 'Support hours', 'amount' => 1000]],
        ], $headers)->assertCreated()->assertJsonPath('data.total', 3500)->assertJsonPath('data.status', 'unpaid');
        $invoiceId = $response->json('data.id');

        $payment = ['amount' => 3500, 'gateway' => 'banktransfer', 'reference' => 'bank-77'];
        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", $payment, $headers)
            ->assertOk()
            ->assertJsonPath('data.status', InvoiceStatus::Paid->value)
            ->assertJsonPath('data.balance', 0);
        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", $payment, $headers)->assertOk()->assertJsonCount(1, 'data.payments');

        $this->postJson("/api/v1/invoices/{$invoiceId}/payments", ['amount' => 100, 'gateway' => 'credit', 'reference' => 'x'], $headers)
            ->assertUnprocessable();
    }

    public function test_a_key_suspends_a_service_and_answers_a_ticket(): void
    {
        Mail::fake();
        [, $plain] = ApiToken::issue(Admin::factory()->create(['name' => 'Raz']), 'Helpdesk', true);
        $headers = ['Authorization' => "Bearer {$plain}"];
        $service = Service::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $service->client_id]);

        $this->postJson("/api/v1/services/{$service->id}/suspend", ['reason' => 'Abuse report'], $headers)
            ->assertOk()
            ->assertJsonPath('service.status', ServiceStatus::Suspended->value);
        $this->assertSame('Abuse report', $service->fresh()->suspension_reason);

        $this->postJson("/api/v1/tickets/{$ticket->number}/replies", ['message' => 'We are looking at it.'], $headers)->assertCreated();
        $this->assertSame(TicketStatus::Answered, $ticket->fresh()->status);
        $this->assertSame('We are looking at it.', $ticket->replies()->latest('id')->first()->message);
    }

    public function test_staff_manage_their_own_keys_on_the_profile_page(): void
    {
        $admin = Admin::factory()->create();
        $other = ApiToken::issue(Admin::factory()->create(), 'Not mine', true)[0];

        $this->actingAs($admin, 'admin')->post(route('admin.profile.api-keys.store'), ['name' => 'Accounting sync', 'can_write' => '1', 'current_password' => 'password'])
            ->assertSessionHas('new_api_key');
        $token = $admin->apiTokens()->sole();
        $this->assertTrue($token->can_write);

        // The session holds the key encrypted; the page shows it in plain text once.
        $plain = Crypt::decryptString(session('new_api_key'));
        $this->assertStringStartsWith(ApiToken::PREFIX, $plain);
        $this->assertStringNotContainsString($plain, serialize(session()->all()));
        $this->assertSame($token->id, ApiToken::findByPlainText($plain)?->id);
        $this->get(route('admin.profile.edit'))->assertOk()->assertSee('Accounting sync')->assertSee($plain);

        $this->delete(route('admin.profile.api-keys.destroy', $other))->assertNotFound();
        $this->delete(route('admin.profile.api-keys.destroy', $token))->assertSessionHas('status');
        $this->assertSame(1, ApiToken::query()->count());
    }

    public function test_the_api_is_off_on_the_demo(): void
    {
        config(['nuvabill.demo' => true]);
        [, $plain] = ApiToken::issue(Admin::factory()->create(), 'Demo', true);

        $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$plain}"])->assertForbidden();
    }
}
