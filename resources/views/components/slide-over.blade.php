@props(['title' => '', 'subtitle' => null, 'close' => 'close'])

<div class="fixed inset-0 z-50" x-data @keydown.escape.window="$wire.{{ $close }}()" role="dialog" aria-modal="true">
    <div class="absolute inset-0 animate-fade bg-black/60 backdrop-blur-[2px]" wire:click="{{ $close }}"></div>

    <aside class="absolute inset-y-0 right-0 flex w-full max-w-md animate-slide-in flex-col border-l border-line bg-surface shadow-2xl">
        <header class="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
            <div class="min-w-0">
                <h2 class="truncate text-[15px] font-semibold tracking-tight">{{ $title }}</h2>
                @if ($subtitle)
                    <p class="mt-0.5 truncate text-xs text-ink-3">{{ $subtitle }}</p>
                @endif
            </div>
            <button type="button" class="btn btn-ghost btn-sm" wire:click="{{ $close }}" aria-label="Close">
                <x-ico name="x" />
            </button>
        </header>

        <div class="flex-1 overflow-y-auto px-5 py-4">
            {{ $slot }}
        </div>

        @isset($footer)
            <footer class="flex items-center justify-between gap-2 border-t border-line px-5 py-3">
                {{ $footer }}
            </footer>
        @endisset
    </aside>
</div>
