@props([
    'name',
    'label',
    'options' => [],
    'value' => null,
    'help' => null,
    'required' => false,
    'placeholder' => null,
])
@php
    $id = $attributes->get('id', 'f-'.str_replace(['.', '[', ']'], '-', $name));
    $errorKey = str_replace(['[', ']'], ['.', ''], $name);
    $hasError = $errors->has($errorKey);
    $selected = (string) old($errorKey, $value instanceof \BackedEnum ? $value->value : $value);
@endphp
<div class="field {{ $attributes->get('class') }}">
    <label for="{{ $id }}">{{ $label }}@if ($required) <span aria-hidden="true">*</span>@endif</label>
    <select
        id="{{ $id }}"
        name="{{ $name }}"
        class="select"
        @if ($required) required @endif
        @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        {{ $attributes->except(['class', 'id']) }}
    >
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected($selected === (string) $optionValue)>{{ $optionLabel }}</option>
        @endforeach
    </select>
    @if ($hasError)
        <p class="error" id="{{ $id }}-error">{{ $errors->first($errorKey) }}</p>
    @elseif ($help)
        <p class="help">{{ $help }}</p>
    @endif
</div>
