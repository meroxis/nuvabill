<x-layouts.admin :title="__('Today')">
    @php
        $admin = auth('admin')->user();
        $appConfig = [
            'key' => $pushKey,
            'subscribeUrl' => route('admin.profile.push.store'),
            'forgetUrl' => route('admin.profile.push.forget'),
            'failed' => __('Alerts could not be turned on. Please try again.'),
            'reload' => true,
        ];
    @endphp

    <div class="page-head">
        <div>
            <h1>{{ __('Today') }}</h1>
            <p>{{ __('Hello, :name', ['name' => \Illuminate\Support\Str::before($admin->name, ' ')]) }} · {{ now()->translatedFormat('l, j F') }}</p>
        </div>
    </div>

    @if ($numbers)
        <div class="kpis">
            @foreach ($numbers as $number)
                <a class="kpi" href="{{ $number['url'] }}">
                    <small>{{ $number['label'] }}</small>
                    <b>{{ $number['value'] }}</b>
                    <span @if (! empty($number['tone'])) data-tone="{{ $number['tone'] }}" @endif>{{ $number['note'] }}</span>
                </a>
            @endforeach
        </div>
    @endif

    <section class="card card-flush">
        <div class="card-header"><h2>{{ __('Needs you') }}</h2></div>

        @foreach ($tickets as $ticket)
            @php $late = $ticket->last_reply_at?->lt(now()->subHours(3)); @endphp
            <a class="today-item" href="{{ route('admin.tickets.show', $ticket) }}">
                <x-pill :tone="$late ? 'crit' : 'warn'">{{ __('Ticket') }}</x-pill>
                <span class="today-item-text">
                    <b>#{{ $ticket->number }} {{ $ticket->subject }}</b>
                    <small>{{ $ticket->client?->name }} · {{ __('waiting :time', ['time' => $ticket->last_reply_at?->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true) ?? '—']) }}</small>
                </span>
                <x-icon name="chevron-right" />
            </a>
        @endforeach

        @foreach ($orders as $order)
            <div class="today-item">
                <x-pill tone="warn">{{ __('Order') }}</x-pill>
                <a class="today-item-text" href="{{ route('admin.orders.show', $order) }}" style="color:inherit;text-decoration:none">
                    <b>#{{ $order->number }} · {{ $order->client?->name }}</b>
                    <small>{{ __('Held by the fraud check') }} · {{ money((int) $order->total, (string) $order->currency) }}</small>
                </a>
                <form method="POST" action="{{ route('admin.orders.accept', $order) }}" data-confirm="{{ __('Approve order #:number and set up its services?', ['number' => $order->number]) }}">
                    @csrf
                    <button class="btn btn-sm btn-primary" type="submit">{{ __('Approve') }}</button>
                </form>
            </div>
        @endforeach

        @foreach ($attention as $item)
            <div class="attention" data-tone="{{ $item['tone'] }}">
                <span>{{ $item['text'] }}</span>
                @if ($item['url'])
                    <a class="btn btn-sm" href="{{ $item['url'] }}">{{ $item['action'] }}</a>
                @endif
            </div>
        @endforeach

        @if ($tickets->isEmpty() && $orders->isEmpty() && $attention === [])
            <div class="attention" data-tone="good"><span>{{ __('Nothing needs you right now.') }}</span></div>
        @endif
    </section>

    <section class="card phone-alerts" x-data="phoneApp(@js($appConfig))" style="margin-top:14px">
        <x-icon name="bell" />
        <div style="display:grid;gap:.5rem;min-width:0;flex:1;font-size:.92rem">
            @if ($devices > 0 && $alerts)
                <span>{{ __('Phone alerts are on for :alerts.', ['alerts' => implode(', ', $alerts)]) }} <a href="{{ route('admin.profile.edit') }}#phone-app">{{ __('Change') }}</a></span>
            @elseif ($devices > 0)
                <span>{{ __('All phone alerts are off.') }} <a href="{{ route('admin.profile.edit') }}#phone-app">{{ __('Change') }}</a></span>
            @else
                <span>{{ __('Get alerts about new orders, payments and tickets on this phone.') }} <a href="{{ route('admin.profile.edit') }}#phone-app">{{ __('How it works') }}</a></span>
            @endif
            <div x-show="state === 'off'" x-cloak>
                <button class="btn btn-sm btn-primary" type="button" @click="turnOn" :disabled="busy">{{ __('Turn on alerts on this device') }}</button>
            </div>
            <p class="muted" x-show="state === 'ios-install'" x-cloak style="margin:0;font-size:.85rem">{{ __('On iPhone and iPad, add the app to your Home Screen first: tap Share, then Add to Home Screen. Then open it from there and turn on alerts.') }}</p>
            <p class="muted" x-show="state === 'blocked'" x-cloak style="margin:0;font-size:.85rem">{{ __('Alerts are blocked for this site. Allow notifications for it in your browser or phone settings, then reload this page.') }}</p>
            <p class="flash" data-tone="crit" x-show="error" x-text="error" x-cloak role="alert" style="margin:0"></p>
        </div>
    </section>
</x-layouts.admin>
