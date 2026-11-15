<div class="relative" x-data="{ open: false }" @keydown.escape="open = false" @click.outside="open = false">
    <button type="button" @click="open = !open"
            class="flex h-10 w-full cursor-pointer items-center gap-2.5 rounded-full border border-line bg-surface pr-3 pl-1.5 text-left transition-colors hover:border-line-strong"
            aria-haspopup="listbox" :aria-expanded="open">
        <span class="flex size-7 shrink-0 items-center justify-center rounded-full"
              style="background-color: {{ $current?->color_hex ?? '#A1A1AA' }}26; color: {{ $current?->color_hex ?? '#A1A1AA' }};">
            <x-ico :name="$current ? $current->type->icon() : 'layers'" class="size-3.5" />
        </span>
        <span class="min-w-0 flex-1">
            <span class="block truncate text-[13px] leading-4 font-semibold">{{ $current?->name ?? 'All Workspaces' }}</span>
        </span>
        <x-ico name="chevrons-up-down" class="size-3.5 text-ink-3" />
    </button>

    <div x-show="open" x-cloak x-transition.origin.top.duration.120ms
         class="absolute inset-x-0 top-full z-50 mt-1.5 rounded-xl border border-line-strong bg-raised p-1 shadow-2xl" role="listbox">
        <p class="eyebrow px-2 pt-1.5 pb-1">Scope</p>

        @foreach ($workspaces as $workspace)
            <button type="button" wire:click="switch('{{ $workspace->id }}')" @click="open = false" role="option"
                    class="flex h-8 w-full cursor-pointer items-center gap-2 rounded-lg px-2 text-[13px] hover:bg-white/[0.06]">
                <span class="flex size-5 items-center justify-center rounded-md" style="background-color: {{ $workspace->color_hex }}26; color: {{ $workspace->color_hex }};">
                    <x-ico :name="$workspace->type->icon()" class="size-3" />
                </span>
                <span class="flex-1 truncate text-left">{{ $workspace->name }}</span>
                @if ($current?->id === $workspace->id)
                    <x-ico name="check" class="size-3.5 text-brand" />
                @endif
            </button>
        @endforeach

        <div class="my-1 border-t border-line"></div>

        <button type="button" wire:click="switch('all')" @click="open = false" role="option"
                class="flex h-8 w-full cursor-pointer items-center gap-2 rounded-lg px-2 text-[13px] hover:bg-white/[0.06]">
            <span class="flex size-5 items-center justify-center rounded-md bg-white/10 text-ink-2">
                <x-ico name="layers" class="size-3" />
            </span>
            <span class="flex-1 text-left">All Workspaces</span>
            @if (! $current)
                <x-ico name="check" class="size-3.5 text-brand" />
            @endif
        </button>
    </div>
</div>
