<?php

namespace Tests\Feature\Auth;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * One mailbox, one account: emails are saved in lowercase, so letter case never makes a second
 * account or a failed sign-in, also on SQLite, which compares letter case.
 */
class ClientEmailCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_up_refuses_the_same_email_in_other_letters(): void
    {
        Client::factory()->create(['email' => 'mer.las@example.com']);

        $this->post(route('client.register'), $this->signUp('Mer.Las@Example.com'))->assertSessionHasErrors(['email' => 'The email has already been taken.']);

        $this->assertSame(1, Client::query()->count());
        $this->assertGuest('web');
    }

    public function test_the_email_is_saved_in_lowercase_and_sign_in_ignores_letter_case(): void
    {
        $this->post(route('client.register'), $this->signUp('Mer.Las@Example.com'))->assertRedirect(route('client.dashboard'));
        $client = Client::query()->where('email', 'mer.las@example.com')->firstOrFail();
        $this->post(route('client.logout'));

        $this->post(route('client.login'), ['email' => 'MER.LAS@EXAMPLE.COM', 'password' => 'secret-pass-1'])->assertRedirect(route('client.dashboard'));
        $this->assertAuthenticatedAs($client, 'web');
    }

    public function test_staff_cannot_add_a_second_client_for_the_same_mailbox(): void
    {
        Client::factory()->create(['email' => 'mer.las@example.com']);
        $this->signInAdmin();

        $this->post(route('admin.clients.store'), [
            'first_name' => 'Mer', 'last_name' => 'Las', 'email' => 'MER.LAS@example.com', 'status' => 'active', 'country' => 'US',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, Client::query()->count());
    }

    public function test_older_emails_are_lowercased_unless_another_client_already_has_that_address(): void
    {
        $mixed = Client::factory()->create();
        $taken = Client::factory()->create();
        $twin = Client::factory()->create();
        // Written past the model, as older versions saved them.
        DB::table('clients')->where('id', $mixed->id)->update(['email' => 'Mer.Las@Example.com']);
        DB::table('clients')->where('id', $taken->id)->update(['email' => 'raz@example.com']);
        DB::table('clients')->where('id', $twin->id)->update(['email' => 'Raz@Example.com']);

        (require database_path('migrations/2027_07_02_000031_lowercase_client_emails_auth_accounts.php'))->up();

        $this->assertSame('mer.las@example.com', DB::table('clients')->where('id', $mixed->id)->value('email'));
        $this->assertSame('raz@example.com', DB::table('clients')->where('id', $taken->id)->value('email'));
        $this->assertSame('Raz@Example.com', DB::table('clients')->where('id', $twin->id)->value('email'));
    }

    /**
     * @return array<string, string>
     */
    private function signUp(string $email): array
    {
        return [
            'first_name' => 'Mer',
            'last_name' => 'Las',
            'email' => $email,
            'country' => 'US',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ];
    }
}
