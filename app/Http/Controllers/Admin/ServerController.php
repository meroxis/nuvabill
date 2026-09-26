<?php

namespace App\Http\Controllers\Admin;

use App\Extensions\ExtensionManager;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Provisioning\Provisioner;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ServerController extends Controller
{
    public function index(): View
    {
        return view('admin.servers.index', [
            'servers' => Server::query()->withCount(['services as accounts_count' => fn ($query) => $query->whereIn('status', ['active', 'suspended', 'pending'])])->orderBy('name')->get(),
        ]);
    }

    public function create(ExtensionManager $extensions): View
    {
        return $this->form(new Server(['use_ssl' => true, 'is_active' => true, 'username' => 'root']), $extensions);
    }

    public function store(Request $request, ExtensionManager $extensions): RedirectResponse
    {
        $server = Server::create($this->validated($request, $extensions));
        Activity::log('server.created', "Server {$server->name} added", $server);

        return redirect()->route('admin.servers.index')->with('status', __('Server added. Use “Test connection” to check the details.'));
    }

    public function edit(Server $server, ExtensionManager $extensions): View
    {
        return $this->form($server, $extensions);
    }

    public function update(Request $request, Server $server, ExtensionManager $extensions): RedirectResponse
    {
        $data = $this->validated($request, $extensions);

        foreach (['password', 'api_token'] as $secret) {
            if (blank($data[$secret] ?? null)) {
                unset($data[$secret]);
            }
        }

        $server->update($data);
        Activity::log('server.updated', "Server {$server->name} updated", $server);

        return redirect()->route('admin.servers.index')->with('status', __('Server saved.'));
    }

    public function destroy(Server $server): RedirectResponse
    {
        if ($server->services()->exists()) {
            return back()->with('error', __('Services still use this server. Move them or turn the server off instead.'));
        }

        $server->delete();

        return redirect()->route('admin.servers.index')->with('status', __('Server removed.'));
    }

    public function test(Server $server, Provisioner $provisioner): RedirectResponse
    {
        $result = $provisioner->testConnection($server);

        return back()->with($result->success ? 'status' : 'error', $result->message);
    }

    private function form(Server $server, ExtensionManager $extensions): View
    {
        $modules = $extensions->serverModuleNames();

        return view('admin.servers.form', [
            'server' => $server,
            'modules' => $modules->all(),
            'help' => $modules->keys()->mapWithKeys(fn (string $slug): array => [$slug => [
                'text' => $extensions->serverModule($slug)->serverHelp(),
                'port' => $extensions->serverModule($slug)->defaultPort(),
            ]])->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ExtensionManager $extensions): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'module' => ['required', Rule::in($extensions->serverModuleNames()->keys()->all())],
            'hostname' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9.-]+$/'],
            'ip_address' => ['nullable', 'ip'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'use_ssl' => ['boolean'],
            'username' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:190'],
            'api_token' => ['nullable', 'string', 'max:2000'],
            'max_accounts' => ['nullable', 'integer', 'min:1'],
            'nameservers' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ], ['hostname.regex' => __('Enter only the host name, for example server1.example.com, without https:// or a path.')]);

        $data['nameservers'] = collect(preg_split('/[\s,]+/', (string) ($data['nameservers'] ?? '')))->filter()->values()->all() ?: null;

        return $data;
    }
}
