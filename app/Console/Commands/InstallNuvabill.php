<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\SettingsController;
use App\Support\Installation;
use App\Support\Installer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

#[Signature('nuvabill:install
    {--url= : The address clients will open, for example https://billing.yourhost.com}
    {--db= : The database: mysql or sqlite}
    {--db-host=127.0.0.1 : MySQL or MariaDB host}
    {--db-port=3306 : MySQL or MariaDB port}
    {--db-name= : Database name}
    {--db-user= : Database user}
    {--db-password= : Database password}
    {--sqlite-path= : Where to keep the SQLite file (default: database/database.sqlite)}
    {--company= : Your company name}
    {--company-email= : Your billing email, shown on invoices}
    {--currency=USD : Your currency, for example USD or IQD}
    {--name= : Your name, for the owner account}
    {--email= : Your email, to sign in}
    {--password= : Your password; asked if left out, or made for you with --no-interaction}
    {--demo-products : Add three example hosting plans to the store}')]
#[Description('Install Nuvabill in the terminal: the same steps as the web installer')]
class InstallNuvabill extends Command
{
    public function handle(Installer $installer): int
    {
        if (Installation::isInstalled()) {
            $this->components->error('Nuvabill is already installed here.');

            return self::FAILURE;
        }

        $this->components->info('Installing Nuvabill '.config('nuvabill.version'));

        $failed = collect($installer->requirements())->reject(fn (array $check): bool => $check['ok']);

        if ($failed->isNotEmpty()) {
            $this->components->error('This server is not ready yet:');
            $this->components->bulletList($failed->map(fn (array $check): string => "{$check['label']}. {$check['help']}")->all());

            return self::FAILURE;
        }

        try {
            $database = $this->databaseDetails();
            $account = $this->accountDetails();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $this->step('Creating the database tables', fn () => $installer->setUpDatabase($database));
            $this->step('Creating your account', fn () => $installer->finish($account));
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->done($database['app_url'], $account);

        return self::SUCCESS;
    }

    /**
     * @return array{app_url: string, driver: string, host: string|null, port: int, database: string|null, username: string|null, password: string|null, sqlite_path: string|null}
     */
    private function databaseDetails(): array
    {
        $drivers = array_filter([
            'mysql' => extension_loaded('pdo_mysql') ? 'MySQL or MariaDB (recommended)' : null,
            'sqlite' => extension_loaded('pdo_sqlite') ? 'SQLite file (small sites and testing)' : null,
        ]);

        $data = [
            'app_url' => $this->value('url', fn () => text(
                label: 'Web address of this site',
                placeholder: 'https://billing.yourhost.com',
                required: true,
                validate: fn (string $value): ?string => filter_var($value, FILTER_VALIDATE_URL) ? null : 'Enter a full address that starts with https:// or http://',
                hint: 'Where your clients will open Nuvabill.',
            )),
            'driver' => $this->value('db', fn () => count($drivers) === 1 ? array_key_first($drivers) : select('Database', $drivers, default: 'mysql')),
        ];

        if ($data['driver'] === 'mysql') {
            $data += [
                'host' => (string) $this->option('db-host'),
                'port' => (int) $this->option('db-port'),
                'database' => $this->value('db-name', fn () => text('Database name', required: true, hint: 'Create it first in your hosting panel, for example cPanel → MySQL Databases.')),
                'username' => $this->value('db-user', fn () => text('Database user', required: true)),
                'password' => $this->option('db-password') ?? ($this->input->isInteractive() ? password('Database password') : ''),
            ];
        }

        $this->check($data, [
            'app_url' => ['required', 'url', 'max:190'],
            'driver' => ['required', Rule::in(array_keys($drivers))],
            'database' => ['required_if:driver,mysql', 'nullable', 'string', 'max:64'],
            'username' => ['required_if:driver,mysql', 'nullable', 'string', 'max:64'],
        ]);

        return $data + ['host' => null, 'port' => 3306, 'database' => null, 'username' => null, 'password' => null, 'sqlite_path' => $this->option('sqlite-path')];
    }

    /**
     * @return array{company_name: string, company_email: string, currency: string, name: string, email: string, password: string, demo_products: bool, generated_password: bool}
     */
    private function accountDetails(): array
    {
        $generated = false;
        $data = [
            'company_name' => $this->value('company', fn () => text('Company name', placeholder: 'YourHost', required: true)),
            'company_email' => $this->value('company-email', fn () => text('Billing email', placeholder: 'billing@yourhost.com', required: true, hint: 'Shown on invoices. New order and ticket alerts go here.')),
            'currency' => strtoupper((string) $this->option('currency')),
            'name' => $this->value('name', fn () => text('Your name', required: true)),
            'email' => $this->value('email', fn () => text('Your email', required: true, hint: 'You sign in with it.')),
        ];

        $data['password'] = $this->option('password') ?? ($this->input->isInteractive()
            ? password('Choose a password', required: true, validate: fn (string $value): ?string => mb_strlen($value) < 10 ? 'Use at least 10 characters.' : null)
            : null);

        if ($data['password'] === null) {
            $data['password'] = Str::password(16, symbols: false);
            $generated = true;
        }

        $this->check($data, [
            'company_name' => ['required', 'string', 'max:120'],
            'company_email' => ['required', 'email', 'max:190'],
            'currency' => ['required', Rule::in(SettingsController::CURRENCIES)],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', Password::min(10)],
        ]);

        $demo = (bool) $this->option('demo-products')
            || ($this->input->isInteractive() && confirm('Add three example hosting plans to the store?', default: false, hint: 'You can edit or delete them later.'));

        return $data + ['demo_products' => $demo, 'generated_password' => $generated];
    }

    /**
     * An option, or the answer to a question. Without questions (--no-interaction) the option is required.
     *
     * @param  callable(): mixed  $ask
     */
    private function value(string $option, callable $ask): string
    {
        $value = $this->option($option);

        if (filled($value)) {
            return (string) $value;
        }

        if (! $this->input->isInteractive()) {
            throw new RuntimeException("Add --{$option}=… (there are no questions with --no-interaction).");
        }

        return (string) $ask();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     */
    private function check(array $data, array $rules): void
    {
        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new RuntimeException(implode(' ', $validator->errors()->all()));
        }
    }

    private function step(string $label, callable $step): void
    {
        $this->input->isInteractive() ? spin($step, $label.'…') : $this->components->task($label, $step);
    }

    /**
     * @param  array{email: string, password: string, generated_password: bool}  $account
     */
    private function done(string $url, array $account): void
    {
        $url = rtrim($url, '/');

        $this->newLine();
        $this->components->info('Nuvabill is installed.');
        $this->components->twoColumnDetail('Admin area', $url.'/'.trim((string) config('nuvabill.admin_path'), '/'));
        $this->components->twoColumnDetail('Sign in with', $account['email']);

        if ($account['generated_password']) {
            $this->components->twoColumnDetail('Password', $account['password']);
            $this->components->warn('Save this password now. It is shown only once.');
        }

        $this->newLine();
        $this->line('  Add this line to your cron jobs so renewals, emails and updates run:');
        $this->line('  <fg=cyan>* * * * * cd '.base_path().' && '.PHP_BINARY.' artisan schedule:run >> /dev/null 2>&1</>');
        $this->newLine();
    }
}
