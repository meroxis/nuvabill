{{-- Your profile → Phone app: install the admin area on a phone, turn on alerts, choose which. --}}
@php
    $appConfig = [
        'key' => $pushKey,
        'subscribeUrl' => route('admin.profile.push.store'),
        'forgetUrl' => route('admin.profile.push.forget'),
        'failed' => __('Alerts could not be turned on. Please try again.'),
        'reload' => true,
    ];
    $may = [
        'orders' => $admin->hasPermission('orders.manage'),
        'payments' => $admin->hasPermission('billing.view'),
        'tickets' => $admin->hasPermission('support.manage'),
    ];
    $currency = (string) setting('billing.currency');
@endphp
<section class="card" id="phone-app" x-data="phoneApp(@js($appConfig))" style="display:grid;gap:1rem;margin-top:14px">
    <div class="card-header" style="margin:0">
        <h2>{{ __('Phone app') }}</h2>
        @if ($pushDevices->isNotEmpty())<x-pill tone="good">{{ trans_choice(':count device gets alerts|:count devices get alerts', $pushDevices->count()) }}</x-pill>@endif
    </div>
    <p class="muted" style="margin:0">{{ __('Put the admin area on your phone’s home screen. It opens like an app on the Today screen, and can alert you about new orders, payments and tickets. No app store needed; it works on iPhone and Android.') }}</p>

    <div class="grid-halves" style="align-items:start">
        <div style="display:grid;gap:.7rem">
            <h3 style="margin:0;font-size:1rem">{{ __('1. Install it') }}</h3>
            <p class="muted" style="margin:0;font-size:.9rem" x-show="installed" x-cloak>{{ __('You are using the app now.') }}</p>
            <div x-show="! installed" style="display:grid;gap:.7rem">
                <div x-show="canInstall" x-cloak><button class="btn btn-primary btn-sm" type="button" @click="install"><x-icon name="download" />{{ __('Install the app') }}</button></div>
                <ul class="muted" style="margin:0;padding-inline-start:1.2rem;font-size:.9rem;display:grid;gap:.35rem">
                    <li>{{ __('iPhone and iPad: open this page in Safari, tap Share, then Add to Home Screen.') }}</li>
                    <li>{{ __('Android: open this page in Chrome, open the menu, then Install app or Add to Home screen.') }}</li>
                </ul>
                <div class="phone-qr">
                    <div class="qr-box" role="img" aria-label="{{ __('QR code for :app', ['app' => __('Phone app')]) }}">{!! $appQrCode !!}</div>
                    <p class="muted" style="margin:0;font-size:.85rem">{{ __('On a computer? Scan this with your phone’s camera to open the admin area there, then sign in.') }}</p>
                </div>
            </div>
        </div>

        <div style="display:grid;gap:.7rem">
            <h3 style="margin:0;font-size:1rem">{{ __('2. Turn on alerts') }}</h3>
            <p class="muted" style="margin:0;font-size:.9rem" x-show="state === 'checking'">{{ __('Checking this device…') }}</p>
            <div x-show="state === 'off'" x-cloak style="display:grid;gap:.5rem">
                <p class="muted" style="margin:0;font-size:.9rem">{{ __('This device does not get alerts yet.') }}</p>
                <div><button class="btn btn-primary btn-sm" type="button" @click="turnOn" :disabled="busy"><x-icon name="bell" />{{ __('Turn on alerts on this device') }}</button></div>
            </div>
            <div x-show="state === 'on'" x-cloak style="display:grid;gap:.5rem">
                <p style="margin:0;font-size:.9rem"><x-pill tone="good">{{ __('On') }}</x-pill> {{ __('This device gets alerts.') }}</p>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <form method="POST" action="{{ route('admin.profile.push.test') }}">@csrf<button class="btn btn-sm" type="submit">{{ __('Send a test alert') }}</button></form>
                    <button class="btn btn-sm btn-ghost" type="button" @click="turnOff" :disabled="busy">{{ __('Turn off on this device') }}</button>
                </div>
            </div>
            <p class="muted" x-show="state === 'ios-install'" x-cloak style="margin:0;font-size:.9rem">{{ __('On iPhone and iPad, add the app to your Home Screen first: tap Share, then Add to Home Screen. Then open it from there and turn on alerts.') }}</p>
            <p class="muted" x-show="state === 'blocked'" x-cloak style="margin:0;font-size:.9rem">{{ __('Alerts are blocked for this site. Allow notifications for it in your browser or phone settings, then reload this page.') }}</p>
            <p class="muted" x-show="state === 'unsupported'" x-cloak style="margin:0;font-size:.9rem">{{ __('This browser cannot show alerts. Try an up-to-date Chrome, Edge, Firefox or Safari.') }}</p>
            <p class="flash" data-tone="crit" x-show="error" x-text="error" x-cloak role="alert" style="margin:0"></p>
        </div>
    </div>

    @if ($may['orders'] || $may['payments'] || $may['tickets'])
        <form method="POST" action="{{ route('admin.profile.push.alerts') }}" style="display:grid;gap:.8rem;border-top:1px solid var(--nb-line);padding-top:1rem">
            @csrf
            @method('PUT')
            <h3 style="margin:0;font-size:1rem">{{ __('Which alerts you get') }}</h3>
            <div class="form-grid">
                @if ($may['orders'])
                    <x-checkbox name="orders" :label="__('New orders')" :checked="$pushAlerts['orders']" />
                @endif
                @if ($may['tickets'])
                    <x-checkbox name="tickets" :label="__('New tickets')" :checked="$pushAlerts['tickets']" />
                    <x-checkbox name="replies" :label="__('Client replies on tickets')" :checked="$pushAlerts['replies']" :help="__('A ticket assigned to someone only alerts them.')" />
                @endif
                @if ($may['payments'])
                    <x-checkbox name="payments" :label="__('Payments')" :checked="$pushAlerts['payments']" />
                    <x-input name="payments_over" type="number" step="0.01" min="0" inputmode="decimal" :label="__('Only payments of at least (:currency)', ['currency' => $currency])" :value="\App\Support\Money::toDecimal($pushAlerts['payments_over'])" :help="__('0 alerts you about every payment.')" />
                @endif
            </div>
            <div><button class="btn btn-sm btn-primary" type="submit">{{ __('Save alerts') }}</button></div>
        </form>
    @endif

    @if ($pushDevices->isNotEmpty())
        <div class="table-wrap"><table class="table">
            <thead><tr><th>{{ __('Device') }}</th><th>{{ __('Added') }}</th><th>{{ __('Last alert') }}</th><th></th></tr></thead>
            <tbody>
            @foreach ($pushDevices as $device)
                <tr>
                    <td><x-icon name="phone" /> {{ $device->device ?? __('Device') }}</td>
                    <td>{{ $device->created_at->diffForHumans() }}</td>
                    <td>{{ $device->last_sent_at?->diffForHumans() ?? __('Never') }}</td>
                    <td class="end">
                        <form method="POST" action="{{ route('admin.profile.push.destroy', $device) }}" data-confirm="{{ __('Stop alerts on this device?') }}">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-ghost" type="submit" aria-label="{{ __('Remove :name', ['name' => $device->device ?? __('Device')]) }}"><x-icon name="trash" /></button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif
</section>
