<x-layouts.admin :title="__('Support departments')">
    <div class="page-head"><div><h1>{{ __('Settings') }}</h1></div></div>
    @include('admin.settings.nav')

    <section class="card" style="display:grid;gap:1rem">
        <div class="card-header" style="margin:0"><h2>{{ __('Support departments') }}</h2><span class="faint" style="font-size:.85rem">{{ __('Clients pick one when they open a ticket.') }}</span></div>
        @foreach ($departments as $department)
            <form method="POST" action="{{ route('admin.settings.departments.update', $department) }}" style="display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1.4fr) 90px auto auto;gap:8px;align-items:end;padding-bottom:1rem;border-bottom:1px solid var(--nb-line)">
                @csrf
                @method('PUT')
                <x-input name="name" :label="__('Name')" :value="$department->name" :id="'d-name-'.$department->id" required />
                <x-input name="email" type="email" :label="__('Alert email')" :value="$department->email" :id="'d-email-'.$department->id" :placeholder="setting('company.email')" />
                <x-input name="sort_order" type="number" min="0" :label="__('Order')" :value="$department->sort_order" :id="'d-order-'.$department->id" />
                <x-checkbox name="is_visible" :label="__('Visible')" :checked="$department->is_visible" :id="'d-visible-'.$department->id" />
                <button class="btn" type="submit">{{ __('Save') }}</button>
            </form>
        @endforeach

        <form method="POST" action="{{ route('admin.settings.departments.store') }}" style="display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1.4fr) auto;gap:8px;align-items:end">
            @csrf
            <input type="hidden" name="is_visible" value="1">
            <x-input name="name" :label="__('New department')" id="d-new-name" required :placeholder="__('For example Sales')" />
            <x-input name="email" type="email" :label="__('Alert email')" id="d-new-email" />
            <button class="btn btn-primary" type="submit"><x-icon name="plus" />{{ __('Add') }}</button>
        </form>
    </section>
</x-layouts.admin>
