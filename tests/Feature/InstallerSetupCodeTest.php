<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\Installation;
use App\Support\Installer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * The web installer of a copy that is not installed yet only goes past its first page for someone
 * who can read the site's files: it asks for the setup code in storage/app/setup-code.txt.
 */
class InstallerSetupCodeTest extends TestCase
{
    use RefreshDatabase;

    private ?string $lockFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lockFile = is_file(Installation::lockPath()) ? file_get_contents(Installation::lockPath()) : null;
        @unlink(Installation::lockPath());
        @unlink(Installation::setupCodePath());
        config(['nuvabill.installed' => false]);
    }

    protected function tearDown(): void
    {
        $this->lockFile === null ? @unlink(Installation::lockPath()) : file_put_contents(Installation::lockPath(), $this->lockFile);
        @unlink(Installation::setupCodePath());

        parent::tearDown();
    }

    public function test_the_first_page_makes_the_code_file_and_asks_for_the_code_without_showing_it(): void
    {
        $this->assertFileDoesNotExist(Installation::setupCodePath());

        $page = $this->get(route('install.welcome'))->assertOk()->assertSee('Setup code')->assertSee('storage/app/setup-code.txt');

        $code = (string) Installation::setupCode();
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}(-[A-HJ-NP-Z2-9]{4}){4}$/', $code);
        $page->assertDontSee($code)->assertDontSee(base_path())->assertDontSee(str_replace('\\', '/', base_path()));

        // The next visitor gets the same code: it is only made once.
        $this->flushSession();
        $this->get(route('install.welcome'))->assertOk()->assertSee('Setup code');
        $this->assertSame($code, Installation::setupCode());
    }

    public function test_a_visitor_without_the_code_cannot_use_any_step(): void
    {
        $installer = $this->recordingInstaller();
        $this->get(route('install.welcome'))->assertOk();

        $this->get(route('install.database'))->assertRedirect(route('install.welcome'));
        $this->post(route('install.database.save'), $this->databaseForm())->assertRedirect(route('install.welcome'));
        $this->get(route('install.account'))->assertRedirect(route('install.welcome'));
        $this->post(route('install.finish'), $this->accountForm())->assertRedirect(route('install.welcome'));

        // A wrong code, no code and a code with one letter missing open nothing.
        foreach (['AAAA-BBBB-CCCC-DDDD-EEEE', '', substr((string) Installation::setupCode(), 0, -1)] as $guess) {
            $this->from(route('install.welcome'))->post(route('install.code'), ['setup_code' => $guess])
                ->assertRedirect(route('install.welcome'))
                ->assertSessionHasErrors('setup_code');
        }

        $this->post(route('install.database.save'), $this->databaseForm())->assertRedirect(route('install.welcome'));
        $this->post(route('install.finish'), $this->accountForm())->assertRedirect(route('install.welcome'));

        $this->assertSame([], $installer->calls);
        $this->assertSame(0, Admin::query()->count());
        $this->assertGuest('admin');
        $this->assertFileDoesNotExist(Installation::lockPath());
    }

    public function test_the_code_from_the_file_opens_the_steps_for_that_visitor_only(): void
    {
        $installer = $this->recordingInstaller();
        $this->get(route('install.welcome'))->assertOk();
        $code = (string) Installation::setupCode();

        // Typed in small letters, without the dashes and with a space: still the same code.
        $this->post(route('install.code'), ['setup_code' => ' '.strtolower(str_replace('-', '', $code))])->assertRedirect(route('install.database'));

        $this->get(route('install.welcome'))->assertOk()->assertDontSee('Setup code')->assertSee('Everything is ready. Continue');
        $this->get(route('install.database'))->assertOk();
        $this->post(route('install.database.save'), $this->databaseForm())->assertRedirect(route('install.account'));
        $this->get(route('install.account'))->assertOk();
        $this->assertSame(['setUpDatabase'], $installer->calls);

        // Someone else, in another browser, still has to type it.
        $this->flushSession();
        $this->get(route('install.database'))->assertRedirect(route('install.welcome'));
        $this->post(route('install.database.save'), $this->databaseForm())->assertRedirect(route('install.welcome'));
        $this->post(route('install.finish'), $this->accountForm())->assertRedirect(route('install.welcome'));
        $this->assertSame(['setUpDatabase'], $installer->calls);
    }

    public function test_installing_removes_the_code_and_a_reopened_installer_asks_for_a_new_one(): void
    {
        Installation::ensureSetupCode();
        $first = (string) Installation::setupCode();
        $this->post(route('install.code'), ['setup_code' => $first])->assertRedirect(route('install.database'));

        $this->post(route('install.finish'), $this->accountForm())->assertRedirect(route('admin.dashboard'));
        $this->assertSame('raz@example.test', Admin::query()->sole()->email);
        $this->assertFileExists(Installation::lockPath());
        $this->assertFileDoesNotExist(Installation::setupCodePath());

        // The lock file and the staff account are gone (an empty database under a copied site): the
        // installer opens again with a new code, and the code typed before no longer counts.
        @unlink(Installation::lockPath());
        Admin::query()->delete();
        auth('admin')->logout();
        session([Installation::SETUP_CODE_SESSION => hash('sha256', 'nuvabill-setup-code:'.$first)]);

        $this->get(route('install.welcome'))->assertOk()->assertSee('Setup code');
        $this->assertNotNull(Installation::setupCode());
        $this->assertNotSame($first, Installation::setupCode());
        $this->post(route('install.code'), ['setup_code' => $first])->assertSessionHasErrors('setup_code');
        $this->post(route('install.finish'), $this->accountForm())->assertRedirect(route('install.welcome'));
        $this->assertSame(0, Admin::query()->count());
    }

    public function test_the_database_in_env_is_kept_while_its_server_is_down(): void
    {
        $installer = $this->recordingInstaller(savedDatabaseIsDown: true);
        Installation::ensureSetupCode();
        $this->post(route('install.code'), ['setup_code' => Installation::setupCode()])->assertRedirect(route('install.database'));

        $this->from(route('install.database'))->post(route('install.database.save'), $this->databaseForm())
            ->assertRedirect(route('install.database'))
            ->assertSessionHasErrors(['host' => 'The .env file already names a database, and it does not answer right now. Start the database server and try again. To use another database, remove the DB_ lines from the .env file first.']);

        $this->assertSame([], $installer->calls);
        $this->assertNull(session()->getOldInput('password'));
    }

    public function test_only_a_database_server_that_does_not_answer_counts_as_down(): void
    {
        $installer = new Installer;
        $default = config('database.default');

        // The test database answers. So does a new copy, which starts with SQLite.
        $this->assertFalse($installer->savedDatabaseIsDown());

        try {
            // Nothing listens on port 1 of this computer.
            config(['database.default' => 'installer-probe', 'database.connections.installer-probe' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'nuvabill', 'username' => 'nuvabill', 'password' => 'secret',
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'options' => [PDO::ATTR_TIMEOUT => 2],
            ]]);

            $this->assertTrue($installer->savedDatabaseIsDown());

            // No database named at all: nothing to keep.
            config(['database.connections.installer-probe.database' => '']);
            $this->assertFalse($installer->savedDatabaseIsDown());
        } finally {
            DB::purge('installer-probe');
            config(['database.default' => $default]);
        }
    }

    public function test_the_storage_app_folder_is_one_of_the_server_checks(): void
    {
        $this->assertContains('Folder storage/app is writable', array_column((new Installer)->requirements(), 'label'));
    }

    /**
     * An installer that only notes which steps were run.
     */
    private function recordingInstaller(bool $savedDatabaseIsDown = false): Installer
    {
        $installer = new class($savedDatabaseIsDown) extends Installer
        {
            /** @var list<string> */
            public array $calls = [];

            public function __construct(private readonly bool $down)
            {
                parent::__construct();
            }

            public function setUpDatabase(array $data): void
            {
                $this->calls[] = 'setUpDatabase';
            }

            public function finish(array $data): Admin
            {
                $this->calls[] = 'finish';

                return new Admin;
            }

            public function savedDatabaseIsDown(): bool
            {
                return $this->down;
            }

            public function databaseIsReady(): bool
            {
                return true;
            }
        };

        $this->app->instance(Installer::class, $installer);

        return $installer;
    }

    /**
     * @return array<string, int|string>
     */
    private function databaseForm(): array
    {
        return ['app_url' => 'https://billing.example.test', 'driver' => 'mysql', 'host' => 'db.example.test', 'port' => 3306, 'database' => 'nuvabill', 'username' => 'nuvabill', 'password' => 'secret'];
    }

    /**
     * @return array<string, string>
     */
    private function accountForm(): array
    {
        return [
            'company_name' => 'Raz Hosting',
            'company_email' => 'billing@example.test',
            'currency' => 'USD',
            'name' => 'Raz',
            'email' => 'raz@example.test',
            'password' => 'a-long-password-123',
            'password_confirmation' => 'a-long-password-123',
        ];
    }
}
