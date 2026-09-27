@php
    $start = $product->startingPrice($currency);
    $features = collect(preg_split('/\R/', (string) $product->description))->map(fn ($line) => trim($line, " \t-*•"))->filter()->take(6);
@endphp
<article class="plan">
    <div>
        <h3><bdi>{{ $product->name }}</bdi></h3>
        <p class="muted" style="margin:.2rem 0 0;font-size:.85rem">{{ $product->type->label() }}</p>
    </div>
    @if ($start)
        <div class="price">
            @if ($start->price === 0)
                {{ __('Free') }}
            @else
                {{ money($start->price, $currency) }}<small>{{ $start->billing_cycle->suffix() }}</small>
            @endif
        </div>
    @endif
    @if ($features->isNotEmpty())
        <ul class="features">
            @foreach ($features as $feature)
                <li><bdi>{{ $feature }}</bdi></li>
            @endforeach
        </ul>
    @endif
    <a class="btn btn-primary btn-block" href="{{ route('store.product', [$group, $product]) }}">{{ __('Choose :plan', ['plan' => $product->name]) }}</a>
</article>
