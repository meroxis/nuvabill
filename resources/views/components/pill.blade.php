@props(['tone' => null])
<span class="pill" @if ($tone) data-tone="{{ $tone }}" @endif {{ $attributes }}>{{ $slot }}</span>
