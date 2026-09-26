<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Import\Whmcs\WhmcsImporter;
use App\Jobs\ImportFromWhmcs;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Settings → Import: move clients, services, invoices and tickets over from WHMCS.
 */
class ImportController extends Controller
{
    public function index(): View
    {
        $connection = (array) setting('import.whmcs');

        return view('admin.settings.import', [
            'connection' => Arr::except($connection, ['password']),
            'hasPassword' => filled($connection['password'] ?? null),
            'status' => ImportFromWhmcs::status(),
            'running' => ImportFromWhmcs::isRunning(),
            'steps' => WhmcsImporter::STEPS,
            'check' => session('import_check'),
        ]);
    }

    /**
     * Save the WHMCS database details after checking they work.
     */
    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64'],
            'username' => ['required', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);

        $data['port'] = (int) ($data['port'] ?? 3306);
        $data['password'] = filled($data['password'] ?? null) ? $data['password'] : ((array) setting('import.whmcs'))['password'] ?? '';

        try {
            $check = WhmcsImporter::connect($data)->check();
        } catch (Throwable $exception) {
            return back()
                ->withInput($request->except('password'))
                ->with('error', __('Could not read the WHMCS database: :error', ['error' => Str::limit($exception->getMessage(), 300)]));
        }

        $settings->set('import.whmcs', $data);

        return back()
            ->with('status', __('Connected to WHMCS :version. Check the numbers below, then start the import.', ['version' => $check['version']]))
            ->with('import_check', $check);
    }

    public function start(): RedirectResponse
    {
        if (blank(((array) setting('import.whmcs'))['database'] ?? null)) {
            return back()->with('error', __('Save the WHMCS database details first.'));
        }

        if (ImportFromWhmcs::isRunning()) {
            return back()->with('error', __('The import is already running.'));
        }

        ImportFromWhmcs::start();
        Activity::log('import.started', 'WHMCS import started.');

        return back()->with('status', __('The import has started. It runs in the background, so you can leave this page.'));
    }

    public function cancel(Settings $settings): RedirectResponse
    {
        $status = ImportFromWhmcs::status();

        if (($status['state'] ?? null) === 'running') {
            $settings->set('import.whmcs_status', ['state' => 'cancelled', 'finished_at' => now()->toIso8601String()] + $status);
            Activity::log('import.cancelled', 'WHMCS import stopped by staff.');
        }

        return back()->with('status', __('The import is stopped. Records already imported stay.'));
    }
}
