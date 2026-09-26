<x-layouts.admin :title="'.'.$price->tld">
    <div class="page-head">
        <div>
            <p class="eyebrow">{{ __('Domain extension') }}</p>
            <h1 style="margin-top:.2rem">.{{ $price->tld }} <span class="faint" style="font-size:1rem">{{ $price->currency }}</span></h1>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.settings.tlds.update', $price) }}" class="card" style="display:grid;gap:1.1rem;max-width:860px">
        @csrf
        @method('PUT')
        @include('admin.settings.tlds.form', ['price' => $price])
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
            <a class="btn" href="{{ route('admin.settings.tlds.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>

    <form method="POST" action="{{ route('admin.settings.tlds.destroy', $price) }}" data-confirm="{{ __('Stop selling .:tld and remove its prices? Domains clients already have are not changed.', ['tld' => $price->tld]) }}" style="max-width:860px">
        @csrf
        @method('DELETE')
        <button class="btn btn-danger" type="submit">{{ __('Remove extension') }}</button>
    </form>
</x-layouts.admin>
