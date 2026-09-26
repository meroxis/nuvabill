@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'help' => null,
    'required' => false,
])
@php
    $id = $attributes->get('id', 'f-'.str_replace(['.', '[', ']'], '-', $name));
    $errorKey = str_replace(['[', ']'], ['.', ''], $name);
    $hasError = $errors->has($errorKey);
    $current = $type === 'password' ? null : old($errorKey, $value);
@endphp
<div class="field {{ $attributes->get('class') }}">
    <label for="{{ $id }}">{{ $label }}@if ($required) <span aria-hidden="true">*</span>@endif</label>
    <input
        id="{{ $id }}"
        name="{{ $name }}"
        type="{{ $type }}"
        class="input"
        @if ($current !== null) value="{{ $current }}" @endif
        @if ($required) required @endif
        @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        {{ $attributes->except(['class', 'id']) }}
    >
    @if ($hasError)
        <p class="error" id="{{ $id }}-error">{{ $errors->first($errorKey) }}</p>
    @elseif ($help)
        <p class="help">{{ $help }}</p>
    @endif
</div>
