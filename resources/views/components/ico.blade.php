@props(['name' => 'circle'])
@php
    $class = $attributes->get('class', 'size-4');
    try {
        $svg = svg('lucide-'.$name, $class.' shrink-0')->toHtml();
    } catch (\Throwable) {
        $svg = svg('lucide-circle', $class.' shrink-0')->toHtml();
    }
@endphp
{!! $svg !!}
