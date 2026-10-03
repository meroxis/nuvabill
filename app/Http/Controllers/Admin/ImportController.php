<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Import\ImportSources;
use App\Jobs\RunImport;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Settings → Import: move clients, services, invoices and tickets over from another billing system.
 * Staff connect to its database, see a dry run of what would come across, then start the import.
 */
class ImportController extends Controller
{
    public function index(): View
    {
        $connection = ImportSources::connection();
        $status = RunImport::status();
        $running = RunImport::isRunning();
        $key = $running ? ($status['source'] ?? 'whmcs') : ($connection['source'] ?? $status['source'] ?? 'whmcs');
        $source = ImportSources::SOURCES[$key] ?? ImportSources::SOURCES['whmcs'];
        $preview = (array) setting('import.preview');

        return view('admin.settings.import', [
            'sources' => ImportSources::SOURCES,
            'connection' => Arr::except($connection, ['password', 'key']),
            'hasPassword' => filled($connection['password'] ?? null),
            'hasKey' => filled($connection['key'] ?? null),
            'source' => $source,
            // The last import, when it was from this system.
            'status' => ($status['source'] ?? 'whmcs') === $key ? $status : [],
            'running' => $running,
            'steps' => $source::steps(),
            'preview' => ($preview['source'] ?? null) === $key ? $preview : null,
        ]);
    }

    /**
     * Save the database details after checking they work, then make a dry run.
     */
    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['required', Rule::in(array_keys(ImportSources::SOURCES))],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64'],
            'username' => ['required', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:255'],
            'prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_]*$/'],
            'key' => ['nullable', 'string', 'max:500'],
            'tls' => ['nullable', 'boolean'],
            'ssl_ca' => ['nullable', 'string', 'max:500'],
        ]);

        $saved = ImportSources::connection();
        // Encrypted unless staff untick it, for a database server without TLS.
        $data['tls'] = $request->has('tls') ? $request->boolean('tls') : true;
        $data['ssl_ca'] = trim((string) ($data['ssl_ca'] ?? ''));
        $data['port'] = (int) ($data['port'] ?? 3306);
        $data['prefix'] = (string) ($data['prefix'] ?? '');
        $data['password'] = filled($data['password'] ?? null) ? $data['password'] : (string) ($saved['password'] ?? '');
        $data['key'] = filled($data['key'] ?? null) ? trim($data['key']) : (($saved['source'] ?? null) === $data['source'] ? (string) ($saved['key'] ?? '') : '');
        $source = ImportSources::get($data['source']);

        try {
            $importer = $source::connect($data);
            $check = $importer->check();
            $preview = $importer->preflight()->toArray();
        } catch (Throwable $exception) {
            return back()
                ->withInput($request->except('password', 'key'))
                ->with('error', __('Could not read the :system database: :error', ['system' => $source::name(), 'error' => Str::limit($exception->getMessage(), 300)]));
        }

        $settings->set('import.connection', $data);
        $settings->set('import.preview', ['source' => $data['source']] + $preview);

        if ($source::keyChecksPasswords() && filled($data['key'])) {
            $settings->set('import.password_keys', [$data['source'] => $data['key']] + (array) setting('import.password_keys', []));
        }

        return back()->with('status', __('Connected to :system :version. Read the dry run below, then start the import.', ['system' => $source::name(), 'version' => $check['version']]));
    }

    /**
     * Make the dry run again with the saved details, for example after fixing something.
     */
    public function preview(Settings $settings): RedirectResponse
    {
        $connection = ImportSources::connection();

        if (blank($connection['database'] ?? null)) {
            return back()->with('error', __('Save the database details first.'));
        }

        try {
            $preview = ImportSources::connect($connection)->preflight()->toArray();
        } catch (Throwable $exception) {
            return back()->with('error', __('Could not read the :system database: :error', ['system' => ImportSources::get($connection['source'])::name(), 'error' => Str::limit($exception->getMessage(), 300)]));
        }

        $settings->set('import.preview', ['source' => $connection['source']] + $preview);

        return back()->with('status', __('The dry run is up to date.'));
    }

    public function start(): RedirectResponse
    {
        $connection = ImportSources::connection();
        $preview = (array) setting('import.preview');

        if (blank($connection['database'] ?? null)) {
            return back()->with('error', __('Save the database details first.'));
        }

        if (($preview['source'] ?? null) === $connection['source'] && collect($preview['problems'] ?? [])->contains('level', 'error')) {
            return back()->with('error', __('Fix the problems marked in red in the dry run first, then check again.'));
        }

        if (RunImport::isRunning()) {
            return back()->with('error', __('The import is already running.'));
        }

        RunImport::start($connection['source']);
        Activity::log('import.started', ImportSources::get($connection['source'])::name().' import started.');

        return back()->with('status', __('The import has started. It runs in the background, so you can leave this page.'));
    }

    public function cancel(Settings $settings): RedirectResponse
    {
        $status = RunImport::status();

        if (($status['state'] ?? null) === 'running') {
            $settings->set('import.status', ['state' => 'cancelled', 'finished_at' => now()->toIso8601String()] + $status);
            Activity::log('import.cancelled', 'Import stopped by staff.');
        }

        return back()->with('status', __('The import is stopped. Records already imported stay.'));
    }
}
