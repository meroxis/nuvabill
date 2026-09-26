@php $editing = $admin->exists; @endphp
<x-layouts.admin :title="$editing ? $admin->name : __('Add staff')">
    <div class="page-head"><div><h1>{{ $editing ? __('Edit staff member') : __('Add staff member') }}</h1></div></div>

    <form method="POST" action="{{ $editing ? route('admin.settings.staff.update', $admin) : route('admin.settings.staff.store') }}" class="card" style="display:grid;gap:1.1rem;max-width:720px">
        @csrf
        @if ($editing) @method('PUT') @endif
        <div class="form-grid">
            <x-input name="name" :label="__('Name')" :value="$admin->name" required />
            <x-input name="email" type="email" :label="__('Email')" :value="$admin->email" required autocomplete="off" />
            <x-select name="role_id" :label="__('Role')" :options="$roles" :value="$admin->role_id" required />
            <x-input name="password" type="password" :label="$editing ? __('New password') : __('Password')" :help="$editing ? __('Leave empty to keep the current password.') : __('At least 10 characters.')" :required="! $editing" autocomplete="new-password" />
            <x-checkbox name="is_active" :label="__('Can sign in')" :checked="$admin->is_active" />
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
            <a class="btn" href="{{ route('admin.settings.staff.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>
</x-layouts.admin>
