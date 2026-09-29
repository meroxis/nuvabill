{{-- The Support sections: tickets, the knowledge base, announcements and network status. --}}
@php $staff = auth('admin')->user(); @endphp
<nav class="filters" aria-label="{{ __('Support sections') }}">
    @if ($staff->hasPermission('support.manage'))
        <a class="chip" href="{{ route('admin.tickets.index') }}" @if (request()->routeIs('admin.tickets.*')) aria-current="true" @endif>{{ __('Tickets') }}</a>
    @endif
    @if ($staff->hasPermission('content.manage'))
        <a class="chip" href="{{ route('admin.kb.index') }}" @if (request()->routeIs('admin.kb.*')) aria-current="true" @endif>{{ __('Knowledge base') }}</a>
        <a class="chip" href="{{ route('admin.announcements.index') }}" @if (request()->routeIs('admin.announcements.*')) aria-current="true" @endif>{{ __('Announcements') }}</a>
    @endif
    @if ($staff->hasPermission('status.manage'))
        <a class="chip" href="{{ route('admin.network.index') }}" @if (request()->routeIs('admin.network.*')) aria-current="true" @endif>{{ __('Network status') }}</a>
    @endif
</nav>
