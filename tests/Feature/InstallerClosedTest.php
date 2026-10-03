<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\Installation;
use App\Support\Installer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * The web installer stays closed on a database that already has staff accounts, even when the
 * lock file is missing (for example after restoring a database backup on a fresh upload).
 */
class InstallerClosedTest extends TestCase
{
    use RefreshDatabase;

    private ?string $lockFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lockFile = is_file(Installation::lockPath()) ? file_get_contents(Installation::lockPath()) : null;
        @unlink(Installation::lockPath());
        config(['nuvabill.installed' => '']);
    }

    protected function tearDown(): void
    {
        $this->lockFile === null ? @unlink(Installation::lockPath()) : file_put_contents(Installation::lockPath(), $this->lockFile);

        parent::tearDown();
    }

    public function test_the_installer_stays_closed_when_the_lock_file_is_missing_but_staff_exist(): void
    {
        $owner = Admin::factory()->create(['name' => 'Mer Las', 'email' => 'mer.las@example.test', 'password' => 'original-password-1']);
        $this->setSettings(['company.name' => 'Example Hosting']);

        $this->get(route('install.account'))->assertNotFound();
        $this->assertFileExists(Installation::lockPath());

        // Even with the lock file gone again, every step refuses.
        foreach (['mer.las@example.test', 'raz@example.test'] as $email) {
            @unlink(Installation::lockPath());
            $this->post(route('install.finish'), $this->accountForm($email))->assertNotFound();
        }

        @unlink(Installation::lockPath());
        $this->post(route('install.database.save'), ['app_url' => 'https://billing.example.test', 'driver' => 'mysql', 'host' => 'db.example.test', 'database' => 'x', 'username' => 'x'])
            ->assertNotFound();

        $this->assertGuest('admin');
        $this->assertTrue(Hash::check('original-password-1', $owner->fresh()->password));
        $this->assertSame(1, Admin::query()->count());
        $this->assertSame('Example Hosting', setting('company.name'));
        $this->get(route('admin.login'))->assertOk();
        $this->assertFileExists(Installation::lockPath());
    }

    public function test_the_installer_steps_refuse_on_their_own_when_staff_exist(): void
    {
        config(['nuvabill.installed' => false]);
        $owner = Admin::factory()->create(['name' => 'Mer Las', 'email' => 'mer.las@example.test', 'password' => 'original-password-1']);

        $this->get(route('install.database'))->assertNotFound();
        $this->get(route('install.account'))->assertNotFound();
        $this->post(route('install.finish'), $this->accountForm('mer.las@example.test'))->assertNotFound();
        $this->post(route('install.database.save'), ['app_url' => 'https://billing.example.test', 'driver' => 'sqlite'])->assertNotFound();

        $this->assertGuest('admin');
        $this->assertTrue(Hash::check('original-password-1', $owner->fresh()->password));
    }

    public function test_finishing_never_changes_an_existing_account(): void
    {
        $admin = Admin::factory()->create(['name' => 'Mer Las', 'email' => 'mer.las@example.test', 'password' => 'original-password-1']);
        $roleId = $admin->role_id;

        try {
            app(Installer::class)->finish(['company_name' => 'Example Hosting', 'company_email' => 'billing@example.test', 'currency' => 'USD', 'name' => 'Raz', 'email' => 'mer.las@example.test', 'password' => 'a-new-long-password']);
            $this->fail('The installer changed an existing account.');
        } catch (RuntimeException) {
            // Expected.
        }

        $admin->refresh();
        $this->assertSame('Mer Las', $admin->name);
        $this->assertSame($roleId, $admin->role_id);
        $this->assertTrue(Hash::check('original-password-1', $admin->password));
    }

    public function test_a_fresh_database_still_installs(): void
    {
        $this->get(route('store.index'))->assertRedirect(route('install.welcome'));

        $this->post(route('install.finish'), $this->accountForm('raz@example.test'))->assertRedirect(route('admin.dashboard'));

        $owner = Admin::query()->sole();
        $this->assertSame('raz@example.test', $owner->email);
        $this->assertTrue($owner->role->isOwner());
        $this->assertAuthenticatedAs($owner, 'admin');
        $this->assertFileExists(Installation::lockPath());
    }

    /**
     * @return array<string, string>
     */
    private function accountForm(string $email): array
    {
        return [
            'company_name' => 'Raz Hosting',
            'company_email' => 'billing@example.test',
            'currency' => 'USD',
            'name' => 'Raz',
            'email' => $email,
            'password' => 'attacker-pass-123',
            'password_confirmation' => 'attacker-pass-123',
        ];
    }
}
