@php $editing = $client->exists; @endphp
<x-layouts.admin :title="$editing ? __('Edit :name', ['name' => $client->name]) : __('Add client')">
    <div class="page-head">
        <div>
            <h1>{{ $editing ? __('Edit client') : __('Add client') }}</h1>
            @if ($editing)<p>#{{ $client->id }} · {{ $client->name }}</p>@endif
        </div>
    </div>

    <form method="POST" action="{{ $editing ? route('admin.clients.update', $client) : route('admin.clients.store') }}" class="card" style="display:grid;gap:1.25rem">
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="form-grid">
            <x-input name="first_name" :label="__('First name')" :value="$client->first_name" required autocomplete="off" />
            <x-input name="last_name" :label="__('Last name')" :value="$client->last_name" required autocomplete="off" />
            <x-input name="email" type="email" :label="__('Email')" :value="$client->email" required autocomplete="off" />
            <x-input name="phone" type="tel" :label="__('Phone')" :value="$client->phone" />
            <x-input name="company_name" :label="__('Company')" :value="$client->company_name" class="span-2" />
            <x-input name="address_1" :label="__('Address')" :value="$client->address_1" />
            <x-input name="address_2" :label="__('Address line 2')" :value="$client->address_2" />
            <x-input name="city" :label="__('City')" :value="$client->city" />
            <x-input name="state" :label="__('State or region')" :value="$client->state" />
            <x-input name="postcode" :label="__('Postcode')" :value="$client->postcode" />
            <x-select name="country" :label="__('Country')" :options="\App\Support\Countries::all()" :value="$client->country" :placeholder="__('Choose a country')" />
            <x-select name="status" :label="__('Status')" :options="collect(\App\Enums\ClientStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" :value="$client->status" required />
            <x-input name="password" type="password" :label="$editing ? __('New password') : __('Password')" :help="$editing ? __('Leave empty to keep the current password.') : __('Leave empty and the client can set one with “Forgot password”.')" autocomplete="new-password" />
            <x-input name="tax_id" :label="setting('tax.id_label')" :value="$client->tax_id" />
            <x-checkbox name="tax_exempt" :label="__('Tax exempt')" :help="__('No tax on this client\'s invoices, for example a business with a valid VAT number in another country.')" :checked="(bool) $client->tax_exempt" />
            <x-input name="tags" :label="__('Tags')" :value="implode(', ', $client->tagList())" class="span-2" :help="__('Separate tags with commas, for example VIP, Reseller. Only staff see them; automations can check them.')" autocomplete="off" />
            <x-textarea name="notes" :label="__('Staff notes')" :value="$client->notes" :help="__('Only staff can see these notes.')" class="span-2" rows="3" />
            @unless ($editing)
                <x-checkbox name="send_welcome" :label="__('Send the welcome email')" :checked="true" class="span-2" />
            @endunless
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">{{ $editing ? __('Save client') : __('Create client') }}</button>
            <a class="btn" href="{{ $editing ? route('admin.clients.show', $client) : route('admin.clients.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>
</x-layouts.admin>
