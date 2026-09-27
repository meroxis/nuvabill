<a class="mkt-card" href="{{ route('marketplace.show', $item) }}">
    <div class="mkt-card-head">
        @include('theme::marketplace.partials.icon', ['item' => $item])
        <b class="mkt-price" @if ($item->isFree()) data-free @endif>{{ $item->isFree() ? __('Free') : money($item->price, $item->currency) }}</b>
    </div>
    <div>
        <h3>{{ $item->name }}</h3>
        <div class="mkt-by">{{ $item->type->label() }} · {{ __('by :name', ['name' => $item->developer->name]) }}@if ($item->developer->is_verified || $item->developer->is_official) <x-icon name="shield" />@endif</div>
    </div>
    <p>{{ $item->summary }}</p>
</a>
