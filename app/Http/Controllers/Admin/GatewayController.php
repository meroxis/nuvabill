<?php

namespace App\Http\Controllers\Admin;

use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
use App\Extensions\Gateways\Gateway;
use App\Extensions\SettingsForm;
use App\Http\Controllers\Controller;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GatewayController extends Controller
{
    /**
     * The list moved to Extensions in 0.4.9; old links still work.
     */
    public function index(): RedirectResponse
    {
        return redirect()->route('admin.extensions.index', ['tab' => 'gateways']);
    }

    public function edit(string $gateway, ExtensionManager $extensions): View
    {
        $manifest = $this->manifest($gateway, $extensions);
        $instance = $extensions->gateway($gateway);

        return view('admin.settings.gateways.edit', [
            'manifest' => $manifest,
            'fields' => $instance->settingsFields(),
            'values' => $extensions->settings($gateway),
            'enabled' => $extensions->isEnabled($gateway),
            'webhookUrl' => route('webhooks.gateway', $gateway),
            // Shown also when the settings are saved, where the help under a secret is hidden.
            'webhookEvents' => $instance instanceof Gateway ? $instance->webhookEvents() : [],
        ]);
    }

    public function update(Request $request, string $gateway, ExtensionManager $extensions): RedirectResponse
    {
        $manifest = $this->manifest($gateway, $extensions);
        $fields = $extensions->gateway($gateway)->settingsFields();
        $current = $extensions->settings($gateway);
        $enabled = $request->boolean('enabled');

        $input = SettingsForm::validate($request, $fields, $current, $enabled);

        $settings = SettingsForm::values($fields, $input, $current);

        $extensions->saveSettings($gateway, $settings, $enabled);
        Activity::log('gateway.updated', "Payment gateway {$manifest->name} ".($enabled ? 'enabled' : 'disabled'));

        return redirect()->route('admin.extensions.index', ['tab' => 'gateways'])->with('status', __(':name saved.', ['name' => $manifest->name]));
    }

    private function manifest(string $slug, ExtensionManager $extensions): ExtensionManifest
    {
        $manifest = $extensions->find($slug);

        abort_if($manifest === null || $manifest->type !== ExtensionManifest::TYPE_GATEWAY, 404);

        return $manifest;
    }
}
