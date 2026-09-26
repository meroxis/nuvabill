@props(['value'])
{{-- Status pill for any enum with label() and tone(), for example ServiceStatus. --}}
<span class="pill" data-tone="{{ $value->tone() }}" {{ $attributes }}>{{ $value->label() }}</span>
