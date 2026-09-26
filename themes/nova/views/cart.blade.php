@extends('theme::layouts.app')

@section('title', __('Cart'))

@section('content')
    <div class="page-title"><div><h1>{{ __('Your cart') }}</h1></div></div>

    @if ($lines->isEmpty())
        <div class="card empty">
            <strong>{{ __('Your cart is empty') }}</strong>{{ __('Choose a plan in the store to get started.') }}
            <div style="margin-top:1rem"><a class="btn btn-primary" href="{{ route('store.index') }}">{{ __('Go to the store') }}</a></div>
        </div>
    @else
        <div class="two-col">
            <section class="card card-flush">
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>{{ __('Item') }}</th><th class="end">{{ __('Due today') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($lines as $line)
                        <tr>
                            <td>
                                <b>{{ $line->title() }}</b>
                                <div class="muted" style="font-size:.85rem">{{ $line->summary() }}@if ($line->setupFee) · {{ __('includes :fee setup', ['fee' => money($line->setupFee, $currency)]) }}@endif</div>
                            </td>
                            <td class="end num">{{ money($line->dueToday(), $currency) }}</td>
                            <td class="end">
                                <form method="POST" action="{{ route('cart.destroy', $line->index) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-ghost" type="submit" aria-label="{{ __('Remove :item', ['item' => trim($line->title().' '.$line->domain)]) }}"><x-icon name="x" /></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            </section>

            <aside class="card summary">
                <div class="summary-row total"><span>{{ __('Total due today') }}</span><span class="num">{{ money($total, $currency) }}</span></div>
                <a class="btn btn-primary btn-block" href="{{ route('checkout.show') }}">{{ __('Continue to checkout') }}</a>
                <a class="btn btn-block btn-ghost" href="{{ route('store.index') }}">{{ __('Keep shopping') }}</a>
            </aside>
        </div>
    @endif
@endsection
