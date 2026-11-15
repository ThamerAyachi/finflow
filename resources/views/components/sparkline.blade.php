@props(['points' => [], 'color' => '#22C55E', 'width' => 120, 'height' => 32])
@php
    $points = array_values($points);
    $count = count($points);
    $path = '';

    if ($count > 1) {
        $min = min($points);
        $range = (max($points) - $min) ?: 1;
        $coords = [];
        foreach ($points as $i => $value) {
            $x = round($i / ($count - 1) * $width, 2);
            $y = round($height - 3 - (($value - $min) / $range) * ($height - 6), 2);
            $coords[] = "{$x},{$y}";
        }
        $path = implode(' ', $coords);
    }
    $id = 'spark-'.substr(md5($path.$color), 0, 8);
@endphp

@if ($path !== '')
    <svg viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" {{ $attributes->merge(['class' => 'h-8 w-full']) }} aria-hidden="true">
        <defs>
            <linearGradient id="{{ $id }}" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="{{ $color }}" stop-opacity="0.22" />
                <stop offset="100%" stop-color="{{ $color }}" stop-opacity="0" />
            </linearGradient>
        </defs>
        <polygon points="0,{{ $height }} {{ $path }} {{ $width }},{{ $height }}" fill="url(#{{ $id }})" stroke="none" />
        <polyline points="{{ $path }}" fill="none" stroke="{{ $color }}" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
    </svg>
@endif
