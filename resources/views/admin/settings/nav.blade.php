@php $admin = auth('admin')->user(); @endphp
<nav class="filters" aria-label="{{ __('Settings sections') }}">
    @if ($admin->hasPermission('settings.manage'))
        <a class="chip" href="{{ route('admin.settings.edit') }}" @if (request()->routeIs('admin.settings.edit')) aria-current="true" @endif>{{ __('General') }}</a>
        <a class="chip" href="{{ route('admin.settings.gateways.index') }}" @if (request()->routeIs('admin.settings.gateways.*')) aria-current="true" @endif>{{ __('Payment gateways') }}</a>
        <a class="chip" href="{{ route('admin.settings.tlds.index') }}" @if (request()->routeIs('admin.settings.tlds.*')) aria-current="true" @endif>{{ __('Domains') }}</a>
        <a class="chip" href="{{ route('admin.settings.registrars.index') }}" @if (request()->routeIs('admin.settings.registrars.*')) aria-current="true" @endif>{{ __('Registrars') }}</a>
        <a class="chip" href="{{ route('admin.settings.social.edit') }}" @if (request()->routeIs('admin.settings.social.*')) aria-current="true" @endif>{{ __('Social login') }}</a>
        <a class="chip" href="{{ route('admin.settings.email-templates.index') }}" @if (request()->routeIs('admin.settings.email-templates.*')) aria-current="true" @endif>{{ __('Email templates') }}</a>
        <a class="chip" href="{{ route('admin.settings.departments.index') }}" @if (request()->routeIs('admin.settings.departments.*')) aria-current="true" @endif>{{ __('Support departments') }}</a>
    @endif
    @if ($admin->hasPermission('staff.manage'))
        <a class="chip" href="{{ route('admin.settings.staff.index') }}" @if (request()->routeIs('admin.settings.staff.*')) aria-current="true" @endif>{{ __('Staff') }}</a>
        <a class="chip" href="{{ route('admin.settings.roles.index') }}" @if (request()->routeIs('admin.settings.roles.*')) aria-current="true" @endif>{{ __('Roles') }}</a>
    @endif
    @if ($admin->hasPermission('settings.manage'))
        <a class="chip" href="{{ route('admin.settings.activity') }}" @if (request()->routeIs('admin.settings.activity')) aria-current="true" @endif>{{ __('Activity log') }}</a>
        <a class="chip" href="{{ route('admin.settings.import.index') }}" @if (request()->routeIs('admin.settings.import.*')) aria-current="true" @endif>{{ __('Import') }}</a>
    @endif
</nav>
