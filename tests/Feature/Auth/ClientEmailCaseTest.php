<?php

namespace Tests\Feature\Auth;

use App\Health\CheckResult;
use App\Health\Checks\DatabaseHealthChecks;
use App\Health\Status;
use App\Models\ActivityLog;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
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

        Log::spy();

        (require database_path('migrations/2027_07_02_000031_lowercase_client_emails_auth_accounts.php'))->up();

        $this->assertSame('mer.las@example.com', DB::table('clients')->where('id', $mixed->id)->value('email'));
        $this->assertSame('raz@example.com', DB::table('clients')->where('id', $taken->id)->value('email'));
        $this->assertSame('Raz@Example.com', DB::table('clients')->where('id', $twin->id)->value('email'));

        // Staff are told which two accounts share the mailbox.
        $entry = ActivityLog::query()->where('action', 'client.email_duplicate')->sole();
        $this->assertSame($twin->id, $entry->client_id);
        $this->assertSame($twin->id, $entry->subject_id);
        $this->assertStringContainsString('#'.$taken->id, $entry->description);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, '#'.$twin->id) && str_contains($message, '#'.$taken->id));
    }

    public function test_a_client_whose_email_differs_only_in_letter_case_still_signs_in_with_its_own_spelling(): void
    {
        Sleep::fake();
        $lower = Client::factory()->create(['password' => 'lower-password-1']);
        $twin = Client::factory()->create(['password' => 'twin-password-1']);
        // Two accounts for one mailbox, as SQLite sites could get before emails were saved in lowercase.
        DB::table('clients')->where('id', $lower->id)->update(['email' => 'raz@example.com']);
        DB::table('clients')->where('id', $twin->id)->update(['email' => 'Raz@Example.com']);

        $this->post(route('client.login'), ['email' => ' Raz@Example.com ', 'password' => 'twin-password-1'])->assertRedirect(route('client.dashboard'));
        $this->assertAuthenticatedAs($twin->fresh(), 'web');
        $this->post(route('client.logout'));

        $this->post(route('client.login'), ['email' => 'RAZ@example.com', 'password' => 'lower-password-1'])->assertRedirect(route('client.dashboard'));
        $this->assertAuthenticatedAs($lower->fresh(), 'web');
        $this->post(route('client.logout'));

        // Each account needs its own password, and only its own spelling reaches the one with capitals.
        $this->post(route('client.login'), ['email' => 'Raz@Example.com', 'password' => 'lower-password-1'])->assertRedirect(route('client.dashboard'));
        $this->assertAuthenticatedAs($lower->fresh(), 'web');
        $this->post(route('client.logout'));
        $this->post(route('client.login'), ['email' => 'raz@example.com', 'password' => 'twin-password-1'])->assertSessionHasErrors(['email' => 'The email or password is wrong.']);
        $this->assertGuest('web');
    }

    public function test_site_health_lists_clients_that_share_an_email(): void
    {
        $lower = Client::factory()->create();
        $twin = Client::factory()->create();
        Client::factory()->create(['email' => 'mer.las@example.com']);
        $check = fn (): CheckResult => collect(app(DatabaseHealthChecks::class)->run())->firstOrFail(fn (CheckResult $result): bool => $result->id === 'db.client_emails');

        $this->assertSame(Status::Passed, $check()->status);

        DB::table('clients')->where('id', $lower->id)->update(['email' => 'raz@example.com']);
        DB::table('clients')->where('id', $twin->id)->update(['email' => 'Raz@Example.com']);
        $result = $check();

        $this->assertSame(Status::Warning, $result->status);
        $this->assertSame(['count' => 2], $result->params);
        $this->assertSame(['#'.$lower->id, '#'.$twin->id], array_column($result->items, 'label'));
        $this->assertSame(['#'.$twin->id, '#'.$lower->id], array_column($result->items, 'value'));
        $this->assertSame([['client' => $lower->id], ['client' => $twin->id]], array_column($result->items, 'parameters'));
        // Only ids are saved with the results, never the email address.
        $this->assertStringNotContainsString('example.com', json_encode($result->toArray(), JSON_THROW_ON_ERROR));
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
