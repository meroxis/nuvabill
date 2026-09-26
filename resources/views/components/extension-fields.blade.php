@props(['fields', 'values' => [], 'prefix' => 'settings'])
{{-- Renders the settings form an extension describes in settingsFields() or productFields(). --}}
@foreach ($fields as $key => $field)
    @php
        $name = $prefix.'['.$key.']';
        $value = $values[$key] ?? null;
    @endphp
    @switch($field['type'])
        @case('textarea')
            <x-textarea :name="$name" :label="$field['label']" :value="$value" :help="$field['help'] ?? null" :required="$field['required'] ?? false" class="span-2" />
            @break
        @case('select')
            <x-select :name="$name" :label="$field['label']" :options="$field['options'] ?? []" :value="$value" :help="$field['help'] ?? null" :required="$field['required'] ?? false" />
            @break
        @case('password')
            <x-input type="password" :name="$name" :label="$field['label']" :help="filled($value) ? __('Saved. Leave empty to keep it.') : ($field['help'] ?? null)" autocomplete="new-password" />
            @break
        @default
            <x-input :name="$name" :label="$field['label']" :value="$value" :help="$field['help'] ?? null" :required="$field['required'] ?? false" />
    @endswitch
@endforeach
