<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\EmailTemplate;
use App\Models\Role;
use App\Models\Service;
use App\Models\TicketDepartment;
use App\Models\TldPrice;
use App\Providers\AppServiceProvider;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_locked_settings_cannot_be_saved_in_the_demo(): void
    {
        $this->enableDemo();
        $this->signInAdmin();

        $this->from(route('admin.settings.edit'))
            ->put(route('admin.settings.update'), ['company_name' => 'Taken over'])
            ->assertRedirect(route('admin.settings.edit'))
            ->assertSessionHas('error');

        $this->assertNotSame('Taken over', setting('company.name'));
    }

    public function test_email_templates_departments_and_domain_settings_cannot_be_saved_in_the_demo(): void
    {
        $this->enableDemo();
        $this->signInAdmin();
        $template = EmailTemplate::query()->orderBy('id')->firstOrFail();
        $department = TicketDepartment::query()->orderBy('id')->firstOrFail();
        $price = TldPrice::query()->create(['tld' => 'com', 'currency' => 'USD', 'register_price' => 1200, 'transfer_price' => 1200, 'renew_price' => 1200]);
        $subject = $template->subject;
        $name = $department->name;

        $this->put(route('admin.settings.email-templates.update', $template), ['subject' => 'Changed by a visitor', 'body' => 'Changed by a visitor'])->assertSessionHas('error');
        $this->assertSame($subject, $template->fresh()->subject);

        $this->put(route('admin.settings.departments.update', $department), ['name' => 'Changed by a visitor'])->assertSessionHas('error');
        $this->post(route('admin.settings.departments.store'), ['name' => 'Added by a visitor'])->assertSessionHas('error');
        $this->assertSame($name, $department->fresh()->name);
        $this->assertDatabaseMissing('ticket_departments', ['name' => 'Added by a visitor']);

        $this->put(route('admin.settings.tlds.settings'), ['renewal_days_before' => 5])->assertSessionHas('error');
        $this->put(route('admin.settings.tlds.update', $price), ['tld' => 'com', 'currency' => 'USD', 'register_price' => '0', 'transfer_price' => '0', 'renew_price' => '0', 'min_years' => 1, 'max_years' => 1])->assertSessionHas('error');
        $this->delete(route('admin.settings.tlds.destroy', $price))->assertSessionHas('error');
        $this->assertSame(30, setting('domains.renewal_days_before'));
        $this->assertSame(1200, $price->fresh()->register_price);

        // The pages themselves still open.
        $this->get(route('admin.settings.email-templates.edit', $template))->assertOk();
        $this->get(route('admin.settings.departments.index'))->assertOk();
        $this->get(route('admin.settings.tlds.index'))->assertOk();
    }

    public function test_everyday_work_still_saves_in_the_demo(): void
    {
        $this->enableDemo();
        $this->signInAdmin();

        $this->post(route('admin.clients.store'), $this->clientForm('rozh@example.test'))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $this->assertDatabaseHas('clients', ['email' => 'rozh@example.test']);
    }

    public function test_the_shared_demo_client_cannot_be_changed_by_staff(): void
    {
        $this->enableDemo();
        $this->signInAdmin();
        $demoClient = Client::factory()->create(['email' => Demo::CLIENT_EMAIL]);
        $otherClient = Client::factory()->create();

        $this->put(route('admin.clients.update', $demoClient), $this->clientForm('mine@example.test'))->assertSessionHas('error');
        $this->assertSame(Demo::CLIENT_EMAIL, $demoClient->fresh()->email);

        $this->put(route('admin.clients.update', $otherClient), $this->clientForm('mine@example.test'))->assertSessionHasNoErrors();
        $this->assertSame('mine@example.test', $otherClient->fresh()->email);
    }

    public function test_demo_visitors_cannot_change_the_client_password(): void
    {
        $this->enableDemo();
        $client = Client::factory()->create(['email' => Demo::CLIENT_EMAIL]);
        $this->actingAs($client, 'web');

        $this->put(route('client.account.password'), [
            'current_password' => 'password',
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ])->assertSessionHas('error');

        $this->assertTrue(password_verify('password', $client->fresh()->password));
    }

    public function test_server_panel_resource_and_hardware_changes_are_turned_away_in_the_demo(): void
    {
        $this->enableDemo();
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'email' => Demo::CLIENT_EMAIL]);
        $service = Service::factory()->create(['client_id' => $client->id]);
        $page = route('client.services.show', $service);
        $this->actingAs($client, 'web');

        // Without JavaScript the forms go back to the page with the demo message.
        $this->from($page)->post(route('client.services.sections.action', [$service, 'power', 'restart']))
            ->assertRedirect($page)
            ->assertSessionHas('error', __('This is turned off in the demo, so it keeps working for every visitor.'));
        $this->from($page)->post(route('client.services.console.open', $service))->assertRedirect($page)->assertSessionHas('error');
        $this->from($page)->post(route('client.services.resources.store', $service), ['steps' => ['ram' => 2]])->assertRedirect($page)->assertSessionHas('error');

        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $this->from(route('admin.services.show', $service))->post(route('admin.services.machine.assign', $service), ['machine_id' => 1])->assertSessionHas('error');
        $this->post(route('admin.machines.store'), ['name' => 'DS-101'])->assertSessionHas('error');
        $this->assertDatabaseCount('machines', 0);
    }

    public function test_the_sign_in_page_offers_the_demo_account(): void
    {
        $this->enableDemo();
        $admin = Admin::factory()->create([
            'email' => Demo::ADMIN_EMAIL,
            'password' => Demo::PASSWORD,
            'role_id' => Role::query()->where('name', 'Owner')->value('id'),
        ]);

        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee(Demo::ADMIN_EMAIL)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->post(route('admin.login'), ['email' => Demo::ADMIN_EMAIL, 'password' => Demo::PASSWORD])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_normal_sites_show_no_demo_parts(): void
    {
        $this->get(route('admin.login'))
            ->assertOk()
            ->assertDontSee(Demo::ADMIN_EMAIL);

        // Search engines may show a normal store; only the admin area asks them not to.
        $this->get(route('store.index'))->assertOk()->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_the_demo_sends_no_email_and_calls_no_outside_services(): void
    {
        $this->enableDemo();
        config(['mail.default' => 'smtp']);

        $this->app->getProvider(AppServiceProvider::class)->boot();

        $this->assertSame('log', config('mail.default'));
        $this->expectException(StrayRequestException::class);
        Http::get('https://api.stripe.com/v1/charges');
    }

    private function enableDemo(): void
    {
        config(['nuvabill.demo' => true]);
    }

    /**
     * @return array<string, string>
     */
    private function clientForm(string $email): array
    {
        return ['first_name' => 'Raz', 'last_name' => 'Las', 'email' => $email, 'status' => 'active'];
    }
}
