<x-layouts.admin :title="__('Invoices')">
    <div class="page-head">
        <div>
            <h1>{{ __('Invoices') }}</h1>
            <p>{{ trans_choice(':count invoice|:count invoices', $invoices->total(), ['count' => number_format($invoices->total())]) }}</p>
        </div>
        @if (auth('admin')->user()->hasPermission('billing.manage'))
            <a class="btn btn-primary" href="{{ route('admin.invoices.create') }}"><x-icon name="plus" />{{ __('New invoice') }}</a>
        @endif
    </div>

    @include('admin.invoices.nav')

    <div class="filters">
        @foreach (['all' => __('All'), 'unpaid' => __('Unpaid'), 'overdue' => __('Overdue'), 'paid' => __('Paid'), 'draft' => __('Drafts'), 'cancelled' => __('Cancelled')] as $key => $label)
            <a class="chip" href="{{ route('admin.invoices.index', $key === 'all' ? [] : ['status' => $key]) }}" @if ($filter === $key) aria-current="true" @endif>{{ $label }}</a>
        @endforeach
        <form method="GET" action="{{ route('admin.invoices.index') }}" style="margin-inline-start:auto;display:flex;gap:6px">
            @if ($filter !== 'all')<input type="hidden" name="status" value="{{ $filter }}">@endif
            <input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Invoice number or client') }}" aria-label="{{ __('Search invoices') }}" style="width:220px">
        </form>
    </div>

    <section class="card card-flush">
        @include('admin.invoices.partials.table', ['invoices' => $invoices])
        {{ $invoices->links() }}
    </section>
</x-layouts.admin>
