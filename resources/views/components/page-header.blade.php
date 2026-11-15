@props(['title', 'subtitle' => null])

<header class="mb-5 flex flex-wrap items-end justify-between gap-3">
    <div>
        <h1 class="text-xl font-semibold tracking-tight">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-0.5 text-[13px] text-ink-3">{{ $subtitle }}</p>
        @endif
    </div>
    <div class="flex flex-wrap items-center gap-2">
        {{ $slot }}
    </div>
</header>
