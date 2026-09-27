<x-layouts.admin :title="__('Products')">
    <div class="page-head">
        <div>
            <h1>{{ __('Products') }}</h1>
            <p>{{ __('What you sell in your store, grouped into categories.') }}</p>
        </div>
        <div class="form-actions">
            <a class="btn" href="{{ route('admin.product-groups.create') }}"><x-icon name="plus" />{{ __('New group') }}</a>
            @if ($groups->isNotEmpty())
                <a class="btn btn-primary" href="{{ route('admin.products.create') }}"><x-icon name="plus" />{{ __('New product') }}</a>
            @endif
        </div>
    </div>

    @include('admin.products.nav')

    @forelse ($groups as $group)
        <section class="card card-flush">
            <div class="card-header" style="padding-bottom:.85rem;border-bottom:1px solid var(--nb-line);margin:0">
                <div>
                    <h2>{{ $group->name }} @unless ($group->is_visible)<x-pill>{{ __('Hidden') }}</x-pill>@endunless</h2>
                    <span class="faint" style="font-size:.8rem">/store/{{ $group->slug }}</span>
                </div>
                <div class="form-actions">
                    <a class="btn btn-sm" href="{{ route('admin.product-groups.edit', $group) }}">{{ __('Edit group') }}</a>
                    <a class="btn btn-sm" href="{{ route('admin.products.create', ['group' => $group->id]) }}"><x-icon name="plus" />{{ __('Product') }}</a>
                </div>
            </div>
            @if ($group->products->isEmpty())
                <div class="empty">{{ __('No products in this group yet.') }}</div>
            @else
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>{{ __('Product') }}</th><th>{{ __('Starting price') }}</th><th>{{ __('Setup') }}</th><th>{{ __('Store') }}</th></tr></thead>
                    <tbody>
                    @foreach ($group->products as $product)
                        @php $start = $product->startingPrice($currency); @endphp
                        <tr>
                            <td><a class="row-link" href="{{ route('admin.products.edit', $product) }}">{{ $product->name }}</a><div class="faint" style="font-size:.8rem">{{ $product->type->label() }}@if ($product->server_module) · {{ $product->server_module }}@endif</div></td>
                            <td class="num" style="white-space:nowrap">{{ $start ? money($start->price, $currency).$start->billing_cycle->suffix() : __('No price') }}</td>
                            <td>{{ $product->auto_setup->label() }}</td>
                            <td>@if ($product->is_visible)<x-pill tone="good">{{ __('Visible') }}</x-pill>@else<x-pill>{{ __('Hidden') }}</x-pill>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </section>
    @empty
        <section class="card">
            <div class="empty"><strong>{{ __('Start with a product group') }}</strong>{{ __('For example “Web hosting” or “VPS”. Then add products to it.') }}
                <div style="margin-top:1rem"><a class="btn btn-primary" href="{{ route('admin.product-groups.create') }}">{{ __('Create a group') }}</a></div>
            </div>
        </section>
    @endforelse
</x-layouts.admin>
