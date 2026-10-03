<?php

namespace App\Http\Controllers\Admin;

use App\Extensions\ExtensionManager;
use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Provisioning\Provisioner;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ServerController extends Controller
{
    /**
     * Each server with the accounts that count towards its limit, the same count that decides
     * whether it takes a new account.
     */
    public function index(): View
    {
        return view('admin.servers.index', [
            'servers' => Server::query()->withCount('accounts')->orderBy('name')->get(),
        ]);
    }

    /**
     * "?module=cpanel" picks the module, for the links on the Extensions page.
     */
    public function create(Request $request, ExtensionManager $extensions): View
    {
        $module = $extensions->serverModuleNames()->has((string) $request->query('module')) ? (string) $request->query('module') : null;

        return $this->form(new Server(['use_ssl' => true, 'is_active' => true, 'username' => 'root', 'module' => $module]), $extensions);
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
        $server->fill(Arr::except($data, ['password', 'api_token']));

        // A saved password or token is only ever sent to the server it was entered for. When the
        // address or module changes, both must be entered again, so an old secret never goes to a new host.
        $addressChanged = $server->isDirty(['module', 'hostname', 'port', 'use_ssl', 'username']);

        if ($addressChanged && blank($data['password'] ?? null) && blank($data['api_token'] ?? null) && (filled($server->password) || filled($server->api_token))) {
            throw ValidationException::withMessages(['api_token' => __('The server address, port, SSL, user name or module changed. Enter the API token or password again.')]);
        }

        foreach (['password', 'api_token'] as $secret) {
            if (filled($data[$secret] ?? null)) {
                $server->{$secret} = $data[$secret];
            } elseif ($addressChanged) {
                $server->{$secret} = null;
            }
        }

        $server->save();
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
