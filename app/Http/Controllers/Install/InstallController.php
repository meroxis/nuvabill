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
 *
 * Anyone can open the first page of a copy that is not installed yet. The steps after it only
 * open for someone who can read the site's files: they type the setup code from a file in the
 * storage folder (see Installation::ensureSetupCode()).
 */
class InstallController extends Controller
{
    public function __construct(private readonly Installer $installer) {}

    public function welcome(Request $request): View
    {
        $passes = $this->installer->meetsRequirements();

        return view('install.welcome', [
            'checks' => $this->installer->requirements(),
            'passes' => $passes,
            'needsCode' => $passes && Installation::ensureSetupCode() && ! $this->hasSetupCode($request),
        ]);
    }

    public function confirmCode(Request $request): RedirectResponse
    {
        $this->abortIfInstalled();

        $data = $request->validate(['setup_code' => ['required', 'string', 'max:100']]);

        if (! Installation::isSetupCode($data['setup_code'])) {
            return back()->withErrors(['setup_code' => __('That is not the code in the file. Open storage/app/setup-code.txt again and copy the whole line.')]);
        }

        $request->session()->regenerate();
        $request->session()->put(Installation::SETUP_CODE_SESSION, Installation::setupCodeMark());

        return redirect()->route('install.database');
    }

    public function database(Request $request): View|RedirectResponse
    {
        $this->abortIfInstalled();

        if (! $this->installer->meetsRequirements() || ! $this->hasSetupCode($request)) {
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

        if (! $this->hasSetupCode($request)) {
            return redirect()->route('install.welcome');
        }

        $data = $request->validate([
            'app_url' => ['required', 'url', 'max:190'],
            'driver' => ['required', Rule::in(['mysql', 'sqlite'])],
            'host' => ['required_if:driver,mysql', 'nullable', 'string', 'max:190'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'database' => ['required_if:driver,mysql', 'nullable', 'string', 'max:64'],
            'username' => ['required_if:driver,mysql', 'nullable', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:190'],
        ]);

        // A database that is only down for a moment may hold an installed site: .env keeps pointing at it.
        if ($this->installer->savedDatabaseIsDown()) {
            return back()->withInput($request->except('password'))->withErrors(['host' => __('The .env file already names a database, and it does not answer right now. Start the database server and try again. To use another database, remove the DB_ lines from the .env file first.')]);
        }

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

    public function account(Request $request): View|RedirectResponse
    {
        $this->abortIfInstalled();

        if (! $this->hasSetupCode($request)) {
            return redirect()->route('install.welcome');
        }

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

        if (! $this->hasSetupCode($request)) {
            return redirect()->route('install.welcome');
        }

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
        $request->session()->forget(Installation::SETUP_CODE_SESSION);

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

    /**
     * Whether this visitor typed the setup code that is in the file now.
     */
    private function hasSetupCode(Request $request): bool
    {
        $mark = Installation::setupCodeMark();
        $typed = $request->session()->get(Installation::SETUP_CODE_SESSION);

        return $mark !== null && is_string($typed) && hash_equals($mark, $typed);
    }
}
