<?php

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Controller;
use App\Support\Installation;
use App\Support\Installer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use RuntimeException;

/**
 * The three-step web installer: check the server, connect the database, create the owner account.
 * "php artisan nuvabill:install" runs the same steps in a terminal.
 */
class InstallController extends Controller
{
    public function __construct(private readonly Installer $installer) {}

    public function welcome(): View
    {
        return view('install.welcome', [
            'checks' => $this->installer->requirements(),
            'passes' => $this->installer->meetsRequirements(),
        ]);
    }

    public function database(Request $request): View|RedirectResponse
    {
        $this->abortIfInstalled();

        if (! $this->installer->meetsRequirements()) {
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
        $this->abortIfInstalled();

        $data = $request->validate([
            'app_url' => ['required', 'url', 'max:190'],
            'driver' => ['required', Rule::in(['mysql', 'sqlite'])],
            'host' => ['required_if:driver,mysql', 'nullable', 'string', 'max:190'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'database' => ['required_if:driver,mysql', 'nullable', 'string', 'max:64'],
            'username' => ['required_if:driver,mysql', 'nullable', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:190'],
        ]);

        try {
            $this->installer->setUpDatabase($data);
        } catch (RuntimeException $exception) {
            return back()->withInput($request->except('password'))->withErrors(['host' => $exception->getMessage()]);
        }

        // A database with staff accounts (for example a restored backup) is already installed.
        if ($this->installer->hasStaff()) {
            Installation::markInstalled();

            return redirect()->route('admin.login')->with('status', __('This database already has staff accounts, so Nuvabill is ready. Sign in with your account.'));
        }

        return redirect()->route('install.account');
    }

    public function account(): View|RedirectResponse
    {
        $this->abortIfInstalled();

        if (! $this->installer->databaseIsReady()) {
            return redirect()->route('install.database');
        }

        return view('install.account', [
            'currencies' => array_combine(SettingsController::CURRENCIES, SettingsController::CURRENCIES),
        ]);
    }

    public function finish(Request $request): RedirectResponse
    {
        $this->abortIfInstalled();

        if (! $this->installer->databaseIsReady()) {
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

        try {
            $admin = $this->installer->finish(['demo_products' => $request->boolean('demo_products')] + $data);
        } catch (RuntimeException) {
            abort(404);
        }

        Auth::guard('admin')->login($admin);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard')->with('status', __('Nuvabill is installed. Next: add the cron job (Settings → Automation), a payment gateway and your server.'));
    }

    /**
     * The installer never runs on a database that already has staff accounts, even when the lock
     * file is missing: anyone could otherwise create an owner or change the owner's password.
     */
    private function abortIfInstalled(): void
    {
        abort_if($this->installer->hasStaff(), 404);
    }
}
