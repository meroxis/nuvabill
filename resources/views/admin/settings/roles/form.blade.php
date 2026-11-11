@php
    $editing = $role->exists;
    $selected = old('permissions', $role->permissions ?? []);
    // Staff managers who are not owners only tick what their own role has; the rest stays as it is.
    $staff = auth('admin')->user();
    $limited = $staff->role?->isOwner() !== true;
    $kept = $role->permissions ?? [];
@endphp
<x-layouts.admin :title="$editing ? $role->name : __('New role')">
    <div class="page-head"><div><h1>{{ $editing ? __('Edit role') : __('New role') }}</h1><p>{{ __('A role decides what staff can see and change.') }}</p></div></div>

    <form method="POST" action="{{ $editing ? route('admin.settings.roles.update', $role) : route('admin.settings.roles.store') }}" class="card" style="display:grid;gap:1.2rem;max-width:860px">
        @csrf
        @if ($editing) @method('PUT') @endif
        <x-input name="name" :label="__('Role name')" :value="$role->name" required :placeholder="__('For example Support agent')" />
        @if ($limited)
            <p class="help" style="margin:0">{{ __('You can only tick permissions that your own role has. The others stay as they are.') }}</p>
        @endif
        @error('permissions')<p class="error" style="margin:0">{{ $message }}</p>@enderror
        @error('permissions.*')<p class="error" style="margin:0">{{ $message }}</p>@enderror

        {{-- Core's groups, then one per server module with staff pages (its own admin permission). --}}
        @foreach (\App\Models\Role::permissionGroups() as $group => $permissions)
            @continue($group === 'Marketplace store' && ! config('nuvabill.marketplace.store'))
            <fieldset style="border:0;padding:0;margin:0;display:grid;gap:.5rem">
                <legend class="label" style="margin-bottom:.4rem">{{ __($group) }}</legend>
                @foreach ($permissions as $key => $label)
                    @php $locked = $limited && ! $staff->hasPermission($key); @endphp
                    <label class="check"><input type="checkbox" name="permissions[]" value="{{ $key }}" @checked($locked ? in_array($key, $kept, true) : in_array($key, $selected, true)) @disabled($locked)> <span>{{ __($label) }} <span class="faint mono" style="font-size:.75rem">{{ $key }}</span></span></label>
                @endforeach
            </fieldset>
        @endforeach

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">{{ __('Save role') }}</button>
            <a class="btn" href="{{ route('admin.settings.roles.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>
</x-layouts.admin>
