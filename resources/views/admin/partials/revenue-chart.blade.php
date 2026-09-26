@php
    $width = 600;
    $height = 220;
    $left = 58;
    $right = 8;
    $top = 12;
    $bottom = 26;
    $plotHeight = $height - $top - $bottom;
    $slot = ($width - $left - $right) / 12;
    $barWidth = $slot * 0.62;
    $ticks = $chart['max'] > 0 ? [0, $chart['max'] / 2, $chart['max']] : [0];
@endphp
<div style="overflow-x:auto">
    <svg viewBox="0 0 {{ $width }} {{ $height }}" role="img" style="width:100%;min-width:420px;height:auto;display:block"
         aria-label="{{ __('Revenue for each of the last 12 months') }}">
        @foreach ($ticks as $tick)
            @php $y = $top + $plotHeight - ($chart['max'] > 0 ? $tick / $chart['max'] * $plotHeight : 0); @endphp
            <line x1="{{ $left }}" x2="{{ $width - $right }}" y1="{{ $y }}" y2="{{ $y }}" stroke="var(--nb-line)" stroke-width="1"/>
            <text x="{{ $left - 8 }}" y="{{ $y + 4 }}" text-anchor="end" fill="var(--nb-faint)" font-size="11">{{ money((int) $tick, $currency) }}</text>
        @endforeach
        @foreach ($chart['bars'] as $index => $bar)
            @php
                $barHeight = $bar['height'] / 100 * $plotHeight;
                $x = $left + $index * $slot + ($slot - $barWidth) / 2;
                $isLast = $index === count($chart['bars']) - 1;
            @endphp
            <rect x="{{ $x }}" y="{{ $top + $plotHeight - $barHeight }}" width="{{ $barWidth }}" height="{{ max($barHeight, 0) }}" rx="3"
                  fill="var(--nb-accent)" fill-opacity="{{ $isLast ? 1 : 0.45 }}">
                <title>{{ $bar['label'] }}: {{ money($bar['value'], $currency) }}</title>
            </rect>
            <text x="{{ $x + $barWidth / 2 }}" y="{{ $height - 8 }}" text-anchor="middle" fill="var(--nb-faint)" font-size="11">{{ $bar['label'] }}</text>
        @endforeach
    </svg>
</div>
