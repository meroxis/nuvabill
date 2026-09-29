<x-layouts.admin :title="__('Settings')">
    <div class="page-head"><div><h1>{{ __('Settings') }}</h1><p>{{ __('Everything about how your business runs in Nuvabill.') }}</p></div></div>
    <div class="settings-home">
        @include('admin.settings.nav', ['menu' => $menu, 'current' => null])
    </div>
</x-layouts.admin>
