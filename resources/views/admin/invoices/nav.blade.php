<nav class="filters" aria-label="{{ __('Billing sections') }}" style="margin-bottom:10px">
    <a class="chip" href="{{ route('admin.invoices.index') }}" @if (request()->routeIs('admin.invoices.*')) aria-current="true" @endif>{{ __('Invoices') }}</a>
    <a class="chip" href="{{ route('admin.credit-notes.index') }}" @if (request()->routeIs('admin.credit-notes.*')) aria-current="true" @endif>{{ __('Credit notes') }}</a>
    <a class="chip" href="{{ route('admin.exports.index') }}" @if (request()->routeIs('admin.exports.*')) aria-current="true" @endif>{{ __('Exports') }}</a>
</nav>
