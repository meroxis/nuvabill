@extends('theme::layouts.app')

@section('title', __('New ticket'))

@section('content')
    <div class="page-title"><div><h1>{{ __('Open a ticket') }}</h1><p>{{ __('Tell us what you need. The more detail, the faster we can help.') }}</p></div></div>

    <form method="POST" action="{{ route('client.tickets.store') }}" class="card" style="display:grid;gap:1.1rem;max-width:820px">
        @csrf
        <div class="form-grid">
            <x-select name="department_id" :label="__('Department')" :options="$departments->pluck('name', 'id')->all()" :value="$departments->first()?->id" required />
            <x-select name="priority" :label="__('How urgent is it?')" :options="collect(\App\Enums\TicketPriority::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()])->all()" value="medium" required />
            @if ($services->isNotEmpty())
                <x-select name="service_id" :label="__('Which service is it about?')" :options="$services->mapWithKeys(fn ($s) => [$s->id => $s->label()])->all()" :value="request('service')" :placeholder="__('Not about a service')" class="span-2" />
            @endif
            <x-input name="subject" :label="__('Subject')" required class="span-2" />
            <x-textarea name="message" :label="__('Message')" rows="8" required class="span-2" />
        </div>
        <x-captcha form="tickets" />
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">{{ __('Send ticket') }}</button>
            <a class="btn" href="{{ route('client.tickets.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>
@endsection
