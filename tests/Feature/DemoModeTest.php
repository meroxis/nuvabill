<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Role;
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
