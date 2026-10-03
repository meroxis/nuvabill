<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Role;
use Database\Seeders\DefaultDataSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;
use Throwable;

/**
 * The installation steps, shared by the web installer and "php artisan nuvabill:install":
 * check the server, connect the database, create the owner account.
 */
class Installer
{
    public const REQUIRED_EXTENSIONS = ['pdo', 'openssl', 'mbstring', 'tokenizer', 'xml', 'ctype', 'json', 'fileinfo', 'curl', 'zip', 'intl', 'sodium', 'bcmath'];

    public function __construct(private readonly ?string $envPath = null) {}

    /**
     * @return list<array{label: string, ok: bool, help: string}>
     */
    public function requirements(): array
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
            'ok' => is_file($this->envPath()) ? is_writable($this->envPath()) : is_writable(dirname($this->envPath())),
            'help' => 'Make the Nuvabill folder writable for the installer, or create an empty .env file with permissions 644.',
        ];

        return $checks;
    }

    public function meetsRequirements(): bool
    {
        return collect($this->requirements())->every(fn (array $check): bool => $check['ok']);
    }

    /**
     * Connect to the database, save the connection and site address in .env, then create the
     * tables and the default data. Throws a RuntimeException with a message for people.
     *
     * @param  array{app_url: string, driver: string, host?: string|null, port?: int|string|null, database?: string|null, username?: string|null, password?: string|null, sqlite_path?: string|null}  $data
     */
    public function setUpDatabase(array $data): void
    {
        if ($this->hasStaff()) {
            throw new RuntimeException(__('Nuvabill is already installed on this database.'));
        }

        $sqlitePath = filled($data['sqlite_path'] ?? null) ? (string) $data['sqlite_path'] : database_path('database.sqlite');

        $connection = $data['driver'] === 'mysql'
            ? [
                'driver' => 'mysql',
                'host' => $data['host'] ?? '127.0.0.1',
                'port' => (int) ($data['port'] ?? 3306),
                'database' => $data['database'] ?? '',
                'username' => $data['username'] ?? '',
                'password' => (string) ($data['password'] ?? ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ]
            : [
                'driver' => 'sqlite',
                'database' => $sqlitePath,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];

        try {
            if ($connection['driver'] === 'sqlite' && ! is_file($connection['database'])) {
                touch($connection['database']);
            }

            $this->testConnection($connection);
        } catch (Throwable $exception) {
            throw new RuntimeException(__('Could not connect to the database: :error', ['error' => $exception->getMessage()]), previous: $exception);
        }

        $appUrl = rtrim($data['app_url'], '/');
        $env = new EnvFile($this->envPath());
        $env->ensureExists(base_path('.env.example'));

        if (blank($env->get('APP_KEY'))) {
            $env->set(['APP_KEY' => filled(config('app.key')) ? config('app.key') : 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher')))]);
        }

        $env->set([
            'APP_URL' => $appUrl,
            'APP_ENV' => 'production',
            'APP_DEBUG' => false,
            'DB_CONNECTION' => $connection['driver'],
            'DB_HOST' => $connection['host'] ?? null,
            'DB_PORT' => $connection['port'] ?? null,
            'DB_DATABASE' => match (true) {
                $connection['driver'] === 'mysql' => $connection['database'],
                $sqlitePath !== database_path('database.sqlite') => $sqlitePath,
                default => null,
            },
            'DB_USERNAME' => $connection['username'] ?? null,
            'DB_PASSWORD' => $connection['password'] ?? null,
        ]);

        config([
            'database.default' => $connection['driver'],
            "database.connections.{$connection['driver']}" => array_merge(config("database.connections.{$connection['driver']}", []), $connection),
            'app.url' => $appUrl,
        ]);
        DB::purge($connection['driver']);

        try {
            Artisan::call('migrate', ['--force' => true]);
            (new DefaultDataSeeder)->run();
        } catch (Throwable $exception) {
            report($exception);

            throw new RuntimeException(__('Connected, but creating the tables failed: :error', ['error' => $exception->getMessage()]), previous: $exception);
        }
    }

    public function databaseIsReady(): bool
    {
        try {
            return Schema::hasTable('roles') && Role::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the database already has a staff account. Then Nuvabill is installed, and the
     * installer must never create or change an account.
     */
    public function hasStaff(): bool
    {
        return Installation::databaseHasStaff();
    }

    /**
     * Create the owner account and company details, and close the installer. Only for a database
     * without staff accounts: it never changes an existing account.
     *
     * @param  array{company_name: string, company_email: string, currency: string, name: string, email: string, password: string, demo_products?: bool}  $data
     */
    public function finish(array $data): Admin
    {
        if ($this->hasStaff()) {
            throw new RuntimeException(__('Nuvabill is already installed on this database.'));
        }

        $owner = Role::query()->get()->first(fn (Role $role): bool => $role->isOwner());

        $admin = Admin::query()->create([
            'email' => $data['email'],
            'name' => $data['name'],
            'password' => $data['password'],
            'role_id' => $owner?->id,
            'is_active' => true,
        ]);

        app(Settings::class)->setMany([
            'company.name' => $data['company_name'],
            'company.email' => $data['company_email'],
            'billing.currency' => $data['currency'],
            'mail.from_address' => $data['company_email'],
            'mail.from_name' => $data['company_name'],
        ]);

        if (($data['demo_products'] ?? false) && ! config('nuvabill.marketplace.store')) {
            (new DemoCatalogSeeder)->run($data['currency']);
        }

        Installation::markInstalled();

        return $admin;
    }

    private function envPath(): string
    {
        return $this->envPath ?? base_path('.env');
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
}
