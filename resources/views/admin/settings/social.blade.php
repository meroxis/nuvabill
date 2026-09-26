<x-layouts.admin :title="__('Social login')">
    <div class="page-head"><div><h1>{{ __('Settings') }}</h1></div></div>
    @include('admin.settings.nav')

    <form method="POST" action="{{ route('admin.settings.social.update') }}" style="display:grid;gap:14px;max-width:860px">
        @csrf
        @method('PUT')

        <p class="muted" style="margin:0">{{ __('Let clients sign in or create an account with one click. For each one, create an app at the provider, paste its client ID and secret here, and give the provider the redirect address shown.') }}</p>

        @foreach ($providers as $slug => $item)
            <section class="card" style="display:grid;gap:1rem">
                <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
                    <h2 style="font-size:1.05rem;display:flex;gap:.5rem;align-items:center"><x-brand-icon :name="$slug" style="width:20px;height:20px" />{{ $item['provider']->name() }}</h2>
                    <a class="btn btn-sm" href="{{ $item['provider']->consoleUrl() }}" target="_blank" rel="noopener noreferrer"><x-icon name="external" />{{ __('Open :provider apps', ['provider' => $item['provider']->name()]) }}</a>
                </div>
                <x-checkbox :name="$slug.'[enabled]'" :label="__('Allow sign-in with :provider', ['provider' => $item['provider']->name()])" :checked="$item['enabled']" />
                <div class="form-grid">
                    <x-input :name="$slug.'[client_id]'" :label="__('Client ID')" :value="$item['client_id']" autocomplete="off" spellcheck="false" />
                    <x-input :name="$slug.'[client_secret]'" type="password" :label="__('Client secret')" :help="$item['has_secret'] ? __('Saved. Leave empty to keep it.') : null" autocomplete="new-password" />
                </div>
                <div class="field">
                    <label>{{ $slug === 'github' ? __('Authorization callback URL') : __('Authorized redirect URI') }}</label>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <code class="mono" style="overflow-wrap:anywhere">{{ $item['callback'] }}</code>
                        <button type="button" class="btn btn-sm" data-copy="{{ $item['callback'] }}">{{ __('Copy') }}</button>
                    </div>
                    @if ($slug === 'facebook')
                        <p class="help">{{ __('In the Facebook app, add the "Facebook Login" product and ask for the "email" permission. A Facebook sign-in never opens an existing account by email: those clients connect Facebook from their Account page.') }}</p>
                    @elseif ($slug === 'google')
                        <p class="help">{{ __('Create an "OAuth client ID" of type "Web application".') }}</p>
                    @else
                        <p class="help">{{ __('Create a new "OAuth App" under Developer settings.') }}</p>
                    @endif
                </div>
            </section>
        @endforeach

        <div class="form-actions"><button class="btn btn-primary" type="submit">{{ __('Save') }}</button></div>
    </form>
</x-layouts.admin>
