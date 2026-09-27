<nav class="filters" aria-label="{{ __('Product sections') }}">
    <a class="chip" href="{{ route('admin.products.index') }}" @if (request()->routeIs('admin.products.*', 'admin.product-groups.*')) aria-current="true" @endif>{{ __('Products') }}</a>
    <a class="chip" href="{{ route('admin.product-addons.index') }}" @if (request()->routeIs('admin.product-addons.*')) aria-current="true" @endif>{{ __('Add-ons') }}</a>
</nav>
