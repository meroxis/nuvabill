<x-layouts.admin :title="$service->label()">
    @php
        $status = $service->status;
        $hasModule = filled($service->product->server_module);
    @endphp

    <div class="page-head">
        <div>
            <p class="eyebrow">{{ __('Service') }} #{{ $service->id }}</p>
            <h1 style="margin-top:.2rem">{{ $service->domain ?: $service->product->name }} <x-status :value="$status" style="vertical-align:middle" /></h1>
            <p>{{ $service->product->name }} · <a href="{{ route('admin.clients.show', $service->client) }}">{{ $service->client->name }}</a>
                @if ($service->order) · <a href="{{ route('admin.orders.show', $service->order) }}">{{ __('Order #:number', ['number' => $service->order->number]) }}</a>@endif
            </p>
        </div>
    </div>

    @if ($status === \App\Enums\ServiceStatus::Suspended && $service->suspension_reason)
        <div class="flash" data-tone="warn"><span>{{ __('Suspended :date: :reason', ['date' => $service->suspended_at?->translatedFormat('d M Y'), 'reason' => $service->suspension_reason]) }}</span></div>
    @endif

    <section class="card">
        <div class="card-header">
            <h2>{{ $hasModule ? __('Server actions') : __('Status') }}</h2>
            @if ($hasModule)<span class="pill">{{ __('Module: :module', ['module' => $service->product->server_module]) }}</span>@endif
        </div>
        <div class="form-actions">
            @if ($status === \App\Enums\ServiceStatus::Pending)
                <form method="POST" action="{{ route('admin.services.module', [$service, 'create']) }}">@csrf<button class="btn btn-primary" type="submit"><x-icon name="check" />{{ $hasModule ? __('Create account') : __('Activate') }}</button></form>
            @endif
            @if ($status === \App\Enums\ServiceStatus::Active)
                <form method="POST" action="{{ route('admin.services.module', [$service, 'suspend']) }}" style="display:flex;gap:6px" data-confirm="{{ __('Suspend this service? The client loses access until it is unsuspended.') }}">
                    @csrf
                    <input class="input" name="reason" placeholder="{{ __('Reason (optional)') }}" aria-label="{{ __('Suspension reason') }}" style="width:200px">
                    <button class="btn" type="submit">{{ __('Suspend') }}</button>
                </form>
                @if ($hasModule)
                    <form method="POST" action="{{ route('admin.services.module', [$service, 'change-package']) }}">@csrf<button class="btn" type="submit">{{ __('Apply package from product') }}</button></form>
                @endif
            @endif
            @if ($status === \App\Enums\ServiceStatus::Suspended)
                <form method="POST" action="{{ route('admin.services.module', [$service, 'unsuspend']) }}">@csrf<button class="btn btn-primary" type="submit">{{ __('Unsuspend') }}</button></form>
            @endif
            @if (in_array($status, [\App\Enums\ServiceStatus::Active, \App\Enums\ServiceStatus::Suspended, \App\Enums\ServiceStatus::Pending], true))
                <form method="POST" action="{{ route('admin.services.module', [$service, 'terminate']) }}" data-confirm="{{ __('Terminate this service? On a server this deletes the account and all its files. This cannot be undone.') }}">@csrf<button class="btn btn-danger" type="submit">{{ __('Terminate') }}</button></form>
            @endif
        </div>
    </section>

    <div class="grid-2">
        <form method="POST" action="{{ route('admin.services.update', $service) }}" class="card" style="display:grid;gap:1.1rem">
            @csrf
            @method('PUT')
            <div class="card-header" style="margin:0"><h2>{{ __('Details') }}</h2></div>
            <div class="form-grid">
                <x-input name="domain" :label="__('Domain')" :value="$service->domain" />
                <x-select name="server_id" :label="__('Server')" :options="$servers" :value="$service->server_id" :placeholder="__('None')" />
                <x-input name="username" :label="__('Username')" :value="$service->username" autocomplete="off" />
                <x-input name="password" type="password" :label="__('Password')" :help="$service->password ? __('Saved. Leave empty to keep it.') : null" autocomplete="new-password" />
                <x-select name="billing_cycle" :label="__('Billing cycle')" :options="collect(\App\Enums\BillingCycle::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()" :value="$service->billing_cycle" required />
                <x-input name="recurring_amount" type="number" step="0.01" min="0" :label="__('Renewal price (:currency)', ['currency' => $service->currency])" :value="\App\Support\Money::toDecimal($service->recurring_amount)" required />
                <x-input name="next_due_date" type="date" :label="__('Next due date')" :value="$service->next_due_date?->toDateString()" />
                <x-select name="status" :label="__('Status')" :options="collect(\App\Enums\ServiceStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" :value="$status" :help="__('Changing this only updates Nuvabill, not the server.')" required />
            </div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">{{ __('Save details') }}</button></div>
        </form>

        <div style="display:grid;gap:14px;align-content:start">
            <section class="card">
                <dl class="dl">
                    <dt>{{ __('Registered') }}</dt><dd>{{ $service->registration_date->translatedFormat('d M Y') }}</dd>
                    <dt>{{ __('First payment') }}</dt><dd class="num">{{ money($service->first_payment_amount, $service->currency) }}</dd>
                    <dt>{{ __('Server') }}</dt><dd>{{ $service->server ? $service->server->name.' ('.$service->server->hostname.')' : '—' }}</dd>
                    @foreach ($service->addons as $addon)
                        <dt>{{ __('Add-on') }}</dt><dd>{{ $addon->name }} · {{ money($addon->recurring_amount, $service->currency) }}@unless ($addon->isActive()) <x-pill>{{ __('Cancelled') }}</x-pill>@endunless</dd>
                    @endforeach
                    @if ($service->coupon)
                        <dt>{{ __('Coupon') }}</dt><dd><a class="mono" href="{{ route('admin.coupons.edit', $service->coupon) }}">{{ $service->coupon->code }}</a> · {{ $service->coupon_payments_left === null ? __('every renewal') : trans_choice(':count more payment|:count more payments', $service->coupon_payments_left, ['count' => $service->coupon_payments_left]) }}</dd>
                    @endif
                    @if ($service->password)
                        <dt>{{ __('Password') }}</dt><dd><button type="button" class="btn btn-sm" data-copy="{{ $service->password }}" data-copied="{{ __('Copied') }}">{{ __('Copy password') }}</button></dd>
                    @endif
                </dl>
            </section>

            <section class="card" style="display:grid;gap:.8rem">
                <div class="card-header" style="margin:0"><h2>{{ __('Change plan') }}</h2></div>
                @if ($planChange['pending'])
                    @php($pending = $planChange['pending'])
                    <p style="margin:0">
                        @if ($pending->mode === \App\Models\PlanChange::MODE_RENEWAL)
                            {{ __('Moves to :plan on :date.', ['plan' => $pending->toProduct?->name, 'date' => $pending->apply_on->translatedFormat('d M Y')]) }}
                        @else
                            {{ __('Moves to :plan once invoice :number is paid.', ['plan' => $pending->toProduct?->name, 'number' => $pending->invoice?->displayNumber()]) }}
                        @endif
                    </p>
                    <form method="POST" action="{{ route('admin.services.change-plan.destroy', $service) }}" data-confirm="{{ __('Stop this plan change?') }}">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-sm btn-ghost" type="submit">{{ __('Stop this change') }}</button>
                    </form>
                @elseif ($planChange['blocked'])
                    <p class="muted" style="margin:0">{{ $planChange['blocked'] }}</p>
                @elseif ($planChange['options'] === [])
                    <p class="muted" style="margin:0">{{ __('No other product with a price for this billing cycle uses the same server.') }}</p>
                @else
                    <form method="POST" action="{{ route('admin.services.change-plan', $service) }}" style="display:grid;gap:.7rem" data-confirm="{{ __('Change the plan of this service?') }}">
                        @csrf
                        <x-select name="product_id" :label="__('New plan')" :options="$planChange['options']" required :help="__('The amount is the difference for the days left in this period.')" />
                        <label class="check"><input type="radio" name="charge" value="1" checked> <span>{{ __('Invoice the difference (a cheaper plan follows the downgrade setting)') }}</span></label>
                        <label class="check"><input type="radio" name="charge" value="0"> <span>{{ __('Change now without charging or crediting') }}</span></label>
                        <div><button class="btn btn-sm" type="submit">{{ __('Change plan') }}</button></div>
                    </form>
                @endif
            </section>

            <section class="card">
                <div class="card-header"><h2>{{ __('Invoices') }}</h2></div>
                <ul class="list-plain">
                    @forelse ($service->invoiceItems->sortByDesc('id')->take(8) as $item)
                        <li class="feed-item">
                            <span><a class="mono" href="{{ route('admin.invoices.show', $item->invoice) }}">{{ $item->invoice->displayNumber() }}</a> · <span class="num">{{ money($item->amount, $service->currency) }}</span></span>
                            <x-status :value="$item->invoice->status" />
                        </li>
                    @empty
                        <li class="muted">{{ __('No invoices.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</x-layouts.admin>
