{{-- Brand mark: wallet with a rising trend arrow. Inherits colour from `currentColor`.
     stroke-width is set as a style (merged with any caller style) because the global
     `svg { stroke-width }` rule for icons would otherwise override the attribute. --}}
<svg {{ $attributes->merge(['class' => 'size-6', 'style' => 'stroke-width:30']) }} viewBox="100 86 412 412" fill="none" stroke="currentColor"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <path d="M245 207H160a35 35 0 0 0-35 35v183a38 38 0 0 0 38 38h227a32 32 0 0 0 32-32V277"/>
    <path d="M125 262h73"/>
    <path d="M422 337h-75a27.5 27.5 0 0 0 0 55h75z" fill="currentColor" stroke="none"/>
    <path d="M193 332l103-122 50 70 140-158"/>
    <path d="M410 137l78-19-8 77"/>
</svg>
