<?php

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Role;
use App\Support\EnvFile;
use App\Support\Installation;
use App\Support\Settings;
use Database\Seeders\DefaultDataSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use PDO;
use Throwable;

/**
 * The three-step web installer: check the server, connect the database, create the owner account.
 */
class InstallController extends Controller
{
    private const REQUIRED_EXTENSIONS = ['pdo', 'openssl', 'mbstring', 'tokenizer', 'xml', 'ctype', 'json', 'fileinfo', 'curl', 'zip', 'intl', 'sodium', 'bcmath'];

    public function welcome(): View
    {
        $checks = $this->requirements();

        return view('install.welcome', [
            'checks' => $checks,
            'passes' => collect($checks)->every(fn (array $check): bool => $check['ok']),
        ]);
    }

    public function database(Request $request): View|RedirectResponse
    {
        if (! collect($this->requirements())->every(fn (array $check): bool => $check['ok'])) {
            return redirect()->route('install.welcome');
        }

        return view('install.database', [
            'url' => $request->getSchemeAndHttpHost().rtrim($request->getBasePath(), '/'),
            'hasSqlite' => extension_loaded('pdo_sqlite'),
            'hasMysql' => extension_loaded('pdo_mysql'),
        ]);
    }

    public function saveDatabase(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'app_url' => ['required', 'url', 'max:190'],
            'driver' => ['required', Rule::in(['mysql', 'sqlite'])],
            'host' => ['required_if:driver,mysql', 'nullable', 'string', 'max:190'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'database' => ['required_if:driver,mysql', 'nullable', 'string', 'max:64'],
            'username' => ['required_if:driver,mysql', 'nullable', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:190'],
        ]);

        $connection = $data['driver'] === 'mysql'
            ? [
                'driver' => 'mysql',
                'host' => $data['host'],
                'port' => (int) ($data['port'] ?? 3306),
                'database' => $data['database'],
                'username' => $data['username'],
                'password' => $data['password'] ?? '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ]
            : [
                'driver' => 'sqlite',
                'database' => database_path('database.sqlite'),
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];

        try {
            if ($connection['driver'] === 'sqlite' && ! is_file($connection['database'])) {
                touch($connection['database']);
            }

            $this->testConnection($connection);
        } catch (Throwable $exception) {
            return back()->withInput($request->except('password'))->withErrors(['host' => __('Could not connect to the database: :error', ['error' => $exception->getMessage()])]);
        }

        $env = new EnvFile(base_path('.env'));
        $env->ensureExists(base_path('.env.example'));
        $env->set([
            'APP_URL' => rtrim($data['app_url'], '/'),
            'APP_ENV' => 'production',
            'APP_DEBUG' => false,
            'DB_CONNECTION' => $connection['driver'],
            'DB_HOST' => $connection['host'] ?? null,
            'DB_PORT' => $connection['port'] ?? null,
            'DB_DATABASE' => $connection['driver'] === 'mysql' ? $connection['database'] : null,
            'DB_USERNAME' => $connection['username'] ?? null,
            'DB_PASSWORD' => $connection['password'] ?? null,
        ]);

        config([
            'database.default' => $connection['driver'],
            "database.connections.{$connection['driver']}" => array_merge(config("database.connections.{$connection['driver']}", []), $connection),
            'app.url' => rtrim($data['app_url'], '/'),
        ]);
        DB::purge($connection['driver']);

        try {
            Artisan::call('migrate', ['--force' => true]);
            (new DefaultDataSeeder)->run();
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput($request->except('password'))->withErrors(['host' => __('Connected, but creating the tables failed: :error', ['error' => $exception->getMessage()])]);
        }

        return redirect()->route('install.account');
    }

    public function account(): View|RedirectResponse
    {
        if (! $this->databaseIsReady()) {
            return redirect()->route('install.database');
        }

        return view('install.account', [
            'currencies' => array_combine(SettingsController::CURRENCIES, SettingsController::CURRENCIES),
        ]);
    }

    public function finish(Request $request, Settings $settings): RedirectResponse
    {
        if (! $this->databaseIsReady()) {
            return redirect()->route('install.database');
        }

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:120'],
            'company_email' => ['required', 'email', 'max:190'],
            'currency' => ['required', Rule::in(SettingsController::CURRENCIES)],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'confirmed', Password::min(10)],
            'demo_products' => ['boolean'],
        ]);

        $owner = Role::query()->get()->first(fn (Role $role): bool => $role->isOwner());

        $admin = Admin::query()->updateOrCreate(['email' => $data['email']], [
            'name' => $data['name'],
            'password' => $data['password'],
            'role_id' => $owner?->id,
            'is_active' => true,
        ]);

        $settings->setMany([
            'company.name' => $data['company_name'],
            'company.email' => $data['company_email'],
            'billing.currency' => $data['currency'],
            'mail.from_address' => $data['company_email'],
            'mail.from_name' => $data['company_name'],
        ]);

        if ($request->boolean('demo_products')) {
            (new DemoCatalogSeeder)->run($data['currency']);
        }

        Installation::markInstalled();

        Auth::guard('admin')->login($admin);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard')->with('status', __('Nuvabill is installed. Next: add the cron job (Settings → Automation), a payment gateway and your server.'));
    }

    /**
     * @return list<array{label: string, ok: bool, help: string}>
     */
    private function requirements(): array
    {
        $checks = [[
            'label' => 'PHP 8.3 or newer (you have '.PHP_VERSION.')',
            'ok' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'help' => 'Choose PHP 8.3 or newer in your hosting panel (cPanel → Select PHP Version).',
        ]];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $checks[] = [
                'label' => "PHP extension: {$extension}",
                'ok' => extension_loaded($extension),
                'help' => "Turn on the {$extension} extension in your PHP settings.",
            ];
        }

        $checks[] = [
            'label' => 'PHP extension: pdo_mysql or pdo_sqlite',
            'ok' => extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite'),
            'help' => 'Turn on pdo_mysql to use a MySQL or MariaDB database.',
        ];

        foreach (['storage', 'bootstrap/cache'] as $folder) {
            $checks[] = [
                'label' => "Folder {$folder} is writable",
                'ok' => is_writable(base_path($folder)),
                'help' => "Set the permissions of {$folder} to 755 (or 775).",
            ];
        }

        $checks[] = [
            'label' => 'The .env file can be written',
            'ok' => is_file(base_path('.env')) ? is_writable(base_path('.env')) : is_writable(base_path()),
            'help' => 'Make the Nuvabill folder writable for the installer, or create an empty .env file with permissions 644.',
        ];

        return $checks;
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function testConnection(array $connection): void
    {
        if ($connection['driver'] === 'sqlite') {
            new PDO('sqlite:'.$connection['database']);

            return;
        }

        new PDO(
            "mysql:host={$connection['host']};port={$connection['port']};dbname={$connection['database']};charset=utf8mb4",
            $connection['username'],
            $connection['password'],
            [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function databaseIsReady(): bool
    {
        try {
            return Schema::hasTable('roles') && Role::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
