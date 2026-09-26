<x-layouts.guest :title="__('Connect your database')" :subtitle="__('Step 2 of 3 · Create a MySQL or MariaDB database in your hosting panel first.')" :wide="true">
    @php $default = old('driver', $hasMysql ? 'mysql' : 'sqlite'); @endphp
    <form method="POST" action="{{ route('install.database.save') }}" style="display:grid;gap:1rem" x-data="{ driver: @js($default) }">
        @csrf
        @error('host')<div class="flash" data-tone="crit"><span>{{ $message }}</span></div>@enderror

        <x-input name="app_url" type="url" :label="__('Web address of this site')" :value="$url" required :help="__('Where clients will open your store, for example https://billing.yourhost.com')" />

        <div class="field">
            <label for="f-driver">{{ __('Database type') }}</label>
            <select id="f-driver" name="driver" class="select" x-model="driver">
                @if ($hasMysql)<option value="mysql">{{ __('MySQL or MariaDB (recommended)') }}</option>@endif
                @if ($hasSqlite)<option value="sqlite">{{ __('SQLite file (small sites and testing)') }}</option>@endif
            </select>
        </div>

        <template x-if="driver === 'mysql'">
            <div class="form-grid">
                <x-input name="host" :label="__('Host')" value="localhost" />
                <x-input name="port" type="number" :label="__('Port')" value="3306" />
                <x-input name="database" :label="__('Database name')" />
                <x-input name="username" :label="__('Database user')" autocomplete="off" />
                <x-input name="password" type="password" :label="__('Database password')" autocomplete="new-password" class="span-2" />
            </div>
        </template>

        <button class="btn btn-primary btn-block" type="submit">{{ __('Connect and create tables') }}</button>
    </form>
</x-layouts.guest>
