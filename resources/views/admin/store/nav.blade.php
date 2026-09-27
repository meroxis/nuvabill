<nav class="filters" aria-label="{{ __('Store sections') }}">
    <a class="chip" href="{{ route('admin.store.reviews.index') }}" @if (request()->routeIs('admin.store.reviews.*')) aria-current="true" @endif>{{ __('Review queue') }}</a>
    <a class="chip" href="{{ route('admin.store.items.index') }}" @if (request()->routeIs('admin.store.items.*')) aria-current="true" @endif>{{ __('Items') }}</a>
    <a class="chip" href="{{ route('admin.store.developers.index') }}" @if (request()->routeIs('admin.store.developers.*')) aria-current="true" @endif>{{ __('Developers') }}</a>
    <a class="chip" href="{{ route('admin.store.licenses.index') }}" @if (request()->routeIs('admin.store.licenses.*')) aria-current="true" @endif>{{ __('Licenses') }}</a>
    <a class="chip" href="{{ route('admin.store.payouts.index') }}" @if (request()->routeIs('admin.store.payouts.*')) aria-current="true" @endif>{{ __('Payouts and commission') }}</a>
</nav>
