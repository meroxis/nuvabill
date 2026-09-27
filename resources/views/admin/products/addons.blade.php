<x-layouts.admin :title="__('Product add-ons')">
    <div class="page-head">
        <div>
            <h1>{{ __('Products') }}</h1>
            <p>{{ __('Add-ons are extras clients tick when they order, like daily backups. They are billed with the service.') }}</p>
        </div>
        <a class="btn btn-primary" href="{{ route('admin.product-addons.create') }}"><x-icon name="plus" />{{ __('New add-on') }}</a>
    </div>

    @include('admin.products.nav')

    <section class="card card-flush">
        @if ($addons->isEmpty())
            <div class="empty"><strong>{{ __('No add-ons yet') }}</strong>{{ __('For example “Daily backups” for $2.00 a month, offered with every hosting plan.') }}
                <div style="margin-top:1rem"><a class="btn btn-primary" href="{{ route('admin.product-addons.create') }}">{{ __('Create an add-on') }}</a></div>
            </div>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Add-on') }}</th><th>{{ __('Price') }}</th><th>{{ __('Offered with') }}</th><th>{{ __('Store') }}</th></tr></thead>
                <tbody>
                @foreach ($addons as $addon)
                    @php $start = $addon->prices->where('currency', $currency)->first(); @endphp
                    <tr>
                        <td><a class="row-link" href="{{ route('admin.product-addons.edit', $addon) }}">{{ $addon->name }}</a>@if ($addon->is_popular) <x-pill tone="good">{{ __('Popular') }}</x-pill>@endif<div class="faint" style="font-size:.8rem">{{ $addon->description }}</div></td>
                        <td class="num" style="white-space:nowrap">{{ $start ? money($start->price, $currency).$start->billing_cycle->suffix() : __('No price') }}</td>
                        <td style="max-width:320px">{{ empty($addon->product_ids) ? __('All products') : collect($addon->product_ids)->map(fn ($id) => $products[$id] ?? null)->filter()->implode(', ') }}</td>
                        <td>@if ($addon->is_visible)<x-pill tone="good">{{ __('Visible') }}</x-pill>@else<x-pill>{{ __('Hidden') }}</x-pill>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </section>
</x-layouts.admin>
