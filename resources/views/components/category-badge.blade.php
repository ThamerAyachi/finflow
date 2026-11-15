@props(['category' => null, 'clickable' => true])

@if ($category)
    <button type="button"
            @if ($clickable) wire:click.stop="$dispatch('open-category', { id: {{ $category->id }} })" @else tabindex="-1" @endif
            class="badge {{ $clickable ? 'cursor-pointer transition hover:brightness-125' : 'cursor-default' }}"
            style="color: {{ $category->color_hex }}; background-color: {{ $category->color_hex }}1f;">
        <x-ico :name="$category->icon" class="size-3" />
        <span>{{ $category->name }}</span>
    </button>
@else
    <span class="badge bg-white/5 text-ink-3">Uncategorised</span>
@endif
