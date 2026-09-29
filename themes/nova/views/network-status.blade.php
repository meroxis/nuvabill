@extends('theme::layouts.app')

@section('title', __('Network status'))

@section('content')
    <div class="page-title">
        <div>
            <h1>{{ __('Network status') }}</h1>
            <p>{{ __('Is everything working? The state of our servers, open issues and planned maintenance.') }}</p>
        </div>
    </div>

    <div class="status-summary" data-tone="{{ $summary['tone'] }}" role="status">
        <span class="status-dot" aria-hidden="true"></span>
        <span>{{ $summary['label'] }}</span>
    </div>

    @foreach ([[__('Open issues'), $issues], [__('Planned maintenance'), $maintenance]] as [$heading, $list])
        @if ($list->isNotEmpty())
            <section class="card card-flush" style="margin-bottom:18px">
                <div class="card-header"><h2>{{ $heading }}</h2></div>
                @foreach ($list as $incident)
                    @include('theme::network-status-incident', ['incident' => $incident])
                @endforeach
            </section>
        @endif
    @endforeach

    @if ($servers->isNotEmpty())
        <section class="card card-flush" style="margin-bottom:18px">
            <div class="card-header"><h2>{{ __('Servers') }}</h2><span class="muted" style="font-size:.85rem">{{ __('Last 30 days') }}</span></div>
            @foreach ($servers as $server)
                <div class="status-server">
                    <div class="status-server-head">
                        <span class="status-dot" data-tone="{{ $server->status_up === false ? 'crit' : ($server->status_up === null ? 'muted' : 'good') }}" aria-hidden="true"></span>
                        <strong><bdi>{{ $server->publicName() }}</bdi></strong>
                        <span class="muted" style="font-size:.87rem">{{ $server->status_up === false ? __('Offline') : ($server->status_up === null ? __('Not checked yet') : __('Online')) }}</span>
                        @isset($uptime[$server->id])<span class="uptime">{{ __(':percent% uptime', ['percent' => number_format($uptime[$server->id], 2)]) }}</span>@endisset
                    </div>
                    <div class="uptime-bars" dir="ltr">
                        @foreach ($days[$server->id] as $date => $percent)
                            <span data-level="{{ $percent === null ? 'none' : ($percent >= 99.5 ? 'up' : ($percent >= 90 ? 'some' : 'down')) }}" title="{{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('d M') }}: {{ $percent === null ? __('No data') : number_format($percent, 2).'%' }}"></span>
                        @endforeach
                    </div>
                    <div class="uptime-scale" dir="ltr"><span>{{ __(':days days ago', ['days' => 30]) }}</span><span>{{ __('Today') }}</span></div>
                </div>
            @endforeach
        </section>
    @endif

    @if ($recent->isNotEmpty())
        <section class="card card-flush">
            <div class="card-header"><h2>{{ __('Resolved lately') }}</h2></div>
            @foreach ($recent as $incident)
                @include('theme::network-status-incident', ['incident' => $incident])
            @endforeach
        </section>
    @endif
@endsection
