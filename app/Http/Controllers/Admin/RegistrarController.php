<?php

namespace App\Http\Controllers\Admin;

use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
use App\Http\Controllers\Controller;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class RegistrarController extends Controller
{
    /**
     * The list moved to Extensions in 0.4.9; old links still work.
     */
    public function index(): RedirectResponse
    {
        return redirect()->route('admin.extensions.index', ['tab' => 'registrars']);
    }

    public function edit(string $registrar, ExtensionManager $extensions): View
    {
        $manifest = $this->manifest($registrar, $extensions);

        return view('admin.settings.registrars.edit', [
            'manifest' => $manifest,
            'fields' => $extensions->registrar($registrar)->settingsFields(),
            'values' => $extensions->settings($registrar),
            'enabled' => $extensions->isEnabled($registrar),
            'serverIp' => $this->serverIp(),
        ]);
    }

    public function update(Request $request, string $registrar, ExtensionManager $extensions): RedirectResponse
    {
        $manifest = $this->manifest($registrar, $extensions);
        $fields = $extensions->registrar($registrar)->settingsFields();
        $current = $extensions->settings($registrar);
        $enabled = $request->boolean('enabled');

        $rules = ['enabled' => ['boolean']];

        foreach ($fields as $key => $field) {
            $required = ($field['required'] ?? false) && $enabled && ! ($field['type'] === 'password' && filled($current[$key] ?? null));
            $rules["settings.{$key}"] = array_filter([
                $required ? 'required' : 'nullable',
                'string',
                'max:1000',
                isset($field['options']) ? 'in:'.implode(',', array_keys($field['options'])) : null,
            ]);
        }

        $input = $request->validate($rules, [], collect($fields)->mapWithKeys(fn (array $field, string $key): array => ["settings.{$key}" => $field['label']])->all());

        $settings = [];

        foreach ($fields as $key => $field) {
            $value = $input['settings'][$key] ?? null;
            $settings[$key] = ($field['type'] === 'password' && blank($value)) ? ($current[$key] ?? null) : $value;
        }

        $extensions->saveSettings($registrar, $settings, $enabled);
        Activity::log('registrar.updated', "Registrar {$manifest->name} ".($enabled ? 'enabled' : 'disabled'));

        return redirect()->route('admin.settings.registrars.edit', $registrar)->with('status', __(':name saved.', ['name' => $manifest->name]));
    }

    public function test(string $registrar, ExtensionManager $extensions): RedirectResponse
    {
        $this->manifest($registrar, $extensions);

        try {
            $result = $extensions->registrar($registrar)->testConnection();
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }

        return back()->with($result->success ? 'status' : 'error', $result->message);
    }

    private function manifest(string $slug, ExtensionManager $extensions): ExtensionManifest
    {
        $manifest = $extensions->find($slug);

        abort_if($manifest === null || $manifest->type !== ExtensionManifest::TYPE_REGISTRAR, 404);

        return $manifest;
    }

    /**
     * Registrars such as Namecheap and ResellerClub only accept API calls from IP addresses you allow.
     */
    private function serverIp(): ?string
    {
        $ip = request()->server('SERVER_ADDR');

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $ip : null;
    }
}
