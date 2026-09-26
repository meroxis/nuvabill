@php $editing = $server->exists; @endphp
<x-layouts.admin :title="$editing ? __('Edit server') : __('Add server')">
    <div class="page-head"><div><h1>{{ $editing ? __('Edit server') : __('Add server') }}</h1>@if ($editing)<p>{{ $server->hostname }}</p>@endif</div></div>

    <form method="POST" action="{{ $editing ? route('admin.servers.update', $server) : route('admin.servers.store') }}" class="card" style="display:grid;gap:1.1rem;max-width:860px"
          x-data="{ module: @js(old('module', $server->module ?? array_key_first($modules))), help: @js($help) }">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="form-grid">
            <x-input name="name" :label="__('Name')" :value="$server->name" required :help="__('Your own label, for example “Germany 1”.')" />
            <div class="field">
                <label for="f-module">{{ __('Control panel') }}</label>
                <select id="f-module" name="module" class="select" x-model="module" required>
                    @foreach ($modules as $slug => $name)
                        <option value="{{ $slug }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flash span-2" data-tone="info" x-show="help[module] && help[module].text"><span x-text="help[module] ? help[module].text : ''"></span></div>
            <x-input name="hostname" :label="__('Host name')" :value="$server->hostname" required placeholder="server1.example.com" />
            <x-input name="ip_address" :label="__('IP address')" :value="$server->ip_address" />
            <div class="field">
                <label for="f-port">{{ __('Port') }}</label>
                <input id="f-port" name="port" type="number" class="input" value="{{ old('port', $server->port) }}" :placeholder="help[module] ? help[module].port : ''">
                <p class="help">{{ __('Leave empty for the usual port.') }}</p>
            </div>
            <x-checkbox name="use_ssl" :label="__('Connect with HTTPS')" :checked="$server->use_ssl" />
            <x-input name="username" :label="__('Username')" :value="$server->username" autocomplete="off" />
            <x-input name="password" type="password" :label="__('Password')" :help="$server->password ? __('Saved. Leave empty to keep it.') : __('Only if the panel uses a password instead of a token.')" autocomplete="new-password" />
            <x-textarea name="api_token" :label="__('API token')" rows="2" class="span-2" :help="$server->api_token ? __('Saved. Leave empty to keep it.') : null" autocomplete="off" />
            <x-input name="max_accounts" type="number" min="1" :label="__('Maximum accounts')" :value="$server->max_accounts" :help="__('Leave empty for no limit.')" />
            <x-textarea name="nameservers" :label="__('Nameservers')" :value="implode(PHP_EOL, $server->nameservers ?? [])" rows="2" :help="__('One per line. Shown to clients in their welcome email.')" />
            <x-checkbox name="is_active" :label="__('Use this server for new accounts')" :checked="$server->is_active" />
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">{{ __('Save server') }}</button>
            <a class="btn" href="{{ route('admin.servers.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>

    @if ($editing)
        <form method="POST" action="{{ route('admin.servers.destroy', $server) }}" data-confirm="{{ __('Remove this server from Nuvabill? Nothing is deleted on the server itself.') }}">
            @csrf
            @method('DELETE')
            <button class="btn btn-danger btn-sm" type="submit">{{ __('Remove server') }}</button>
        </form>
    @endif
</x-layouts.admin>
