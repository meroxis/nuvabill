@php
    $start = $product->startingPrice($currency);
    $features = collect(\App\Seo\SeoText::featureLines($product->description))->take(6);
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
            @if ($start->setup_fee > 0)
                <small style="display:block;margin-top:.35rem">{{ __('+ :fee setup', ['fee' => money($start->setup_fee, $currency)]) }}</small>
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
