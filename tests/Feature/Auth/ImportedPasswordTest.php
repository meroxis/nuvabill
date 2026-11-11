<?php

namespace Tests\Feature\Auth;

use App\Auth\LegacyPassword;
use App\Models\Client;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
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

    public function test_an_old_md5_hash_is_kept_inside_a_slow_hash_and_still_signs_in_once(): void
    {
        $client = $this->importedClient();
        $md5 = md5('salt-1'.'old-password');

        $this->assertSame(1, LegacyPassword::wrapStored());

        $stored = (string) $client->fresh()->legacy_password;
        $this->assertStringStartsWith('md5-salt-wrapped:$2y$', $stored);
        $this->assertStringEndsWith(':salt-1', $stored);
        $this->assertStringNotContainsString($md5, $stored);
        $this->assertSame(0, DB::table('clients')->where('legacy_password', 'like', '%'.$md5.'%')->count());

        $this->post(route('client.login'), ['email' => 'raz@example.test', 'password' => 'another-password'])->assertSessionHasErrors('email');
        $this->assertGuest('web');
        // The MD5 itself is not the password.
        $this->post(route('client.login'), ['email' => 'raz@example.test', 'password' => $md5])->assertSessionHasErrors('email');
        $this->assertGuest('web');

        $this->post(route('client.login'), ['email' => 'raz@example.test', 'password' => 'old-password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($client, 'web');
        $this->assertNull($client->fresh()->legacy_password);
    }

    public function test_only_md5_hashes_are_wrapped_and_a_broken_one_is_removed(): void
    {
        $argon = 'native:'.password_hash('secret-1', PASSWORD_ARGON2ID);
        $stored = [
            'mer@example.test' => 'md5-salt:'.strtoupper(md5('a:b'.'secret-1')).':a:b',
            'las@example.test' => $argon,
            'raz@example.test' => 'md5-salt:not-a-hash',
        ];

        foreach ($stored as $email => $hash) {
            Client::factory()->create(['email' => $email])->forceFill(['legacy_password' => $hash])->save();
        }

        $normal = Client::factory()->create();

        $this->assertSame(2, LegacyPassword::wrapStored());
        $this->assertSame(0, LegacyPassword::wrapStored(), 'A second run finds nothing to do');

        $mer = (string) Client::query()->where('email', 'mer@example.test')->value('legacy_password');
        $this->assertStringStartsWith('md5-salt-wrapped:', $mer);
        $this->assertTrue(LegacyPassword::check($mer, 'secret-1'), 'A salt with a colon and a hash in capitals still check');
        $this->assertFalse(LegacyPassword::check($mer, 'secret-2'));

        $this->assertSame($argon, Client::query()->where('email', 'las@example.test')->value('legacy_password'));
        $this->assertNull(Client::query()->where('email', 'raz@example.test')->value('legacy_password'));
        $this->assertNull($normal->fresh()->legacy_password);
        $this->assertSame($argon, LegacyPassword::wrap($argon));
    }

    public function test_the_scheduler_wraps_md5_hashes_and_then_rests_until_an_import_or_the_next_hour(): void
    {
        $schedule = new Schedule;
        ScheduleFacade::swap($schedule);
        require base_path('routes/console.php');

        $event = collect($schedule->events())->first(fn (Event $event): bool => $event->description === 'nuvabill:imported-passwords');
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);

        $client = $this->importedClient();
        $this->assertTrue($event->filtersPass($this->app));

        $event->run($this->app);

        $this->assertStringStartsWith('md5-salt-wrapped:', (string) $client->fresh()->legacy_password);
        $this->assertFalse($event->filtersPass($this->app), 'Nothing is left, so the clients table is not read every minute');

        LegacyPassword::wrapSoon();
        $this->assertTrue($event->filtersPass($this->app), 'An import asks for the next run');

        LegacyPassword::wrapStored();
        $this->assertFalse($event->filtersPass($this->app));
        $this->travel(61)->minutes();
        $this->assertTrue($event->filtersPass($this->app), 'Checked again each hour');
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

        $this->actingAs($client, 'web')->put(route('client.account.password'), ['current_password' => 'password', 'password' => 'my-new-password', 'password_confirmation' => 'my-new-password'])
            ->assertSessionHasNoErrors();

        $this->assertNull($client->fresh()->legacy_password);
    }
}
