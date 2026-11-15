@props(['title' => '', 'close' => 'closeForm', 'submit' => null])

<div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 pt-[12vh]"
     x-data @keydown.escape.window="$wire.{{ $close }}()" role="dialog" aria-modal="true">
    <div class="fixed inset-0 animate-fade bg-black/60 backdrop-blur-[2px]" wire:click="{{ $close }}"></div>

    <form @if ($submit) wire:submit="{{ $submit }}" @endif
          class="relative w-full max-w-md animate-pop rounded-xl border border-line-strong bg-surface shadow-2xl">
        <header class="flex items-center justify-between border-b border-line px-5 py-3.5">
            <h2 class="text-[15px] font-semibold tracking-tight">{{ $title }}</h2>
            <button type="button" class="btn btn-ghost btn-sm" wire:click="{{ $close }}" aria-label="Close">
                <x-ico name="x" />
            </button>
        </header>

        <div class="space-y-3.5 px-5 py-4">
            {{ $slot }}
        </div>

        @isset($footer)
            <footer class="flex items-center justify-end gap-2 border-t border-line px-5 py-3">
                {{ $footer }}
            </footer>
        @endisset
    </form>
</div>
