<?php

namespace Tests\Feature\Auth;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A password imported from another billing system works once and is then replaced; any password
 * set on purpose afterwards makes the imported one stop working.
 */
class ImportedPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function importedClient(): Client
    {
        $client = Client::factory()->create(['email' => 'raz@example.test']);
        $client->forceFill(['legacy_password' => 'md5-salt:'.md5('salt-1'.'old-password').':salt-1'])->save();

        return $client->fresh();
    }

    public function test_the_imported_password_signs_in_once_and_becomes_a_normal_password(): void
    {
        $client = $this->importedClient();

        $this->post(route('client.login'), ['email' => 'raz@example.test', 'password' => 'old-password'])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($client, 'web');
        $this->assertNull($client->fresh()->legacy_password);
    }

    public function test_a_password_staff_set_replaces_the_imported_one(): void
    {
        $client = $this->importedClient();
        $this->signInAdmin();

        $this->put(route('admin.clients.update', $client), [
            'first_name' => $client->first_name, 'last_name' => $client->last_name,
            'email' => $client->email, 'status' => 'active', 'password' => 'replacement-password',
        ])->assertSessionHasNoErrors();
        $this->assertNull($client->fresh()->legacy_password);

        auth('admin')->logout();
        $this->post(route('client.login'), ['email' => 'raz@example.test', 'password' => 'old-password'])->assertSessionHasErrors('email');
        $this->assertGuest('web');

        $this->post(route('client.login'), ['email' => 'raz@example.test', 'password' => 'replacement-password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($client, 'web');
    }

    public function test_a_password_the_client_changes_replaces_the_imported_one(): void
    {
        $client = $this->importedClient();
        $client->forceFill(['has_password' => false])->save();

        $this->actingAs($client, 'web')->put(route('client.account.password'), ['password' => 'my-new-password', 'password_confirmation' => 'my-new-password'])
            ->assertSessionHasNoErrors();

        $this->assertNull($client->fresh()->legacy_password);
    }
}
