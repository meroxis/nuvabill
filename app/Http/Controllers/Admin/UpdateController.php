<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Settings;
use App\Updates\UpdateManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class UpdateController extends Controller
{
    public function index(UpdateManager $updates, Settings $settings): View
    {
        return view('admin.updates', [
            'current' => $updates->currentVersion(),
            'release' => $updates->available(),
            'pending' => $updates->hasPendingFinish(),
            'lastChecked' => $settings->get('updates.last_checked_at'),
            'settings' => $settings->all(),
            'hasPublicKey' => filled(config('nuvabill.updates.public_key')),
        ]);
    }

    public function check(UpdateManager $updates): RedirectResponse
    {
        try {
            $release = $updates->check();
        } catch (Throwable $exception) {
            return back()->with('error', __('Could not check for updates: :error', ['error' => $exception->getMessage()]));
        }

        return back()->with('status', $release ? __('Version :version is available.', ['version' => $release->version]) : __('You have the newest version.'));
    }

    public function install(UpdateManager $updates): RedirectResponse
    {
        $release = $updates->available();

        if ($release === null) {
            return back()->with('error', __('There is no update to install. Check for updates first.'));
        }

        try {
            $updates->install($release);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }

        // A new request runs the new code, which then migrates its own database changes.
        return redirect()->route('admin.updates.finish');
    }

    public function finish(UpdateManager $updates): RedirectResponse
    {
        if (! $updates->hasPendingFinish()) {
            return redirect()->route('admin.updates.index');
        }

        try {
            $result = $updates->finish();
        } catch (Throwable $exception) {
            return redirect()->route('admin.updates.index')->with('error', $exception->getMessage());
        }

        return redirect()->route('admin.updates.index')->with('status', __('Updated from :from to :to.', $result));
    }

    public function settings(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(['stable', 'beta'])],
            'auto_security' => ['boolean'],
            'auto_all' => ['boolean'],
        ]);

        $settings->setMany([
            'updates.channel' => $data['channel'],
            'updates.auto_security' => $request->boolean('auto_security'),
            'updates.auto_all' => $request->boolean('auto_all'),
        ]);

        return back()->with('status', __('Update settings saved.'));
    }
}
