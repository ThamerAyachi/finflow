<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Dashboard' }} · {{ config('app.name') }}</title>
    @include('partials.head-icons')
    <script>window.MONEY_BASE = @js(['code' => base_currency()->code, 'decimals' => base_currency()->decimals()]);</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full" x-data="shell" @keydown.window="hotkey($event)" @toast.window="toast($event.detail)">
    @php
        $nav = [
            ['dashboard', 'Dashboard', 'layout-dashboard'],
            ['transactions', 'Transactions', 'arrow-left-right'],
            ['budgets', 'Budgets & Funds', 'piggy-bank'],
            ['debts', 'Debts & Recurring', 'handshake'],
            ['settings', 'Settings', 'settings'],
        ];
    @endphp

    {{-- Mobile top bar --}}
    <div class="sticky top-0 z-30 flex h-12 items-center justify-between border-b border-line bg-canvas/90 px-4 backdrop-blur lg:hidden">
        <button type="button" class="btn btn-ghost btn-sm" @click="nav = !nav" aria-label="Toggle navigation">
            <x-ico name="menu" />
        </button>
        <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2">
            <x-app-icon class="size-6" />
            <span class="font-semibold tracking-tight">{{ config('app.name') }}</span>
        </a>
        <button type="button" class="btn btn-primary btn-sm" @click="$dispatch('quick-entry')" aria-label="New transaction">
            <x-ico name="plus" />
        </button>
    </div>

    <div class="flex min-h-full">
        {{-- Sidebar --}}
        <div x-show="nav" x-cloak class="fixed inset-0 z-30 bg-black/60 lg:hidden" @click="nav = false"></div>
        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-60 -translate-x-full flex-col border-r border-line bg-canvas transition-transform lg:translate-x-0"
            :class="nav && '!translate-x-0'"
        >
            <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2.5 px-4 pt-4 pb-1" aria-label="{{ config('app.name') }} home">
                <x-app-icon class="size-7" />
                <span class="truncate text-[15px] font-semibold tracking-tight">{{ config('app.name') }}</span>
            </a>

            <div class="p-3">
                <livewire:workspace-switcher />
            </div>

            <nav class="flex-1 space-y-0.5 px-3">
                @foreach ($nav as [$route, $label, $icon])
                    <a href="{{ route($route) }}" wire:navigate
                       @class(['nav-link', 'nav-link-active' => request()->routeIs($route)])>
                        <x-ico :name="$icon" class="size-4 {{ request()->routeIs($route) ? 'text-brand' : '' }}" />
                        {{ $label }}
                    </a>
                @endforeach
            </nav>

            <div class="space-y-2 border-t border-line p-3">
                <button type="button" class="btn btn-primary w-full justify-between" @click="$dispatch('quick-entry')">
                    <span class="flex items-center gap-1.5"><x-ico name="plus" /> New transaction</span>
                    <span class="flex items-center gap-0.5 font-mono text-[10px] opacity-70">Ctrl K</span>
                </button>
                <p class="flex items-center justify-between px-1 text-[11px] text-ink-3">
                    <span>Quick add</span>
                    <span class="flex items-center gap-1"><span class="kbd">N</span> or <span class="kbd">Ctrl</span><span class="kbd">K</span></span>
                </p>
            </div>

            @auth
                <div class="flex items-center gap-2.5 border-t border-line p-3">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-white/[0.07] text-[11px] font-semibold uppercase">{{ mb_substr(auth()->user()->name, 0, 2) }}</span>
                    <a href="{{ route('settings', ['tab' => 'account']) }}" wire:navigate class="min-w-0 flex-1">
                        <span class="block truncate text-[13px] leading-4 font-medium">{{ auth()->user()->name }}</span>
                        <span class="block truncate text-[11px] leading-4 text-ink-3">{{ auth()->user()->email }}</span>
                    </a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-sm" aria-label="Sign out" title="Sign out"><x-ico name="log-out" class="size-3.5" /></button>
                    </form>
                </div>
            @endauth
        </aside>

        <main class="min-w-0 flex-1 lg:pl-60">
            <div class="mx-auto max-w-[1400px] px-4 py-5 sm:px-6 lg:px-8 lg:py-7">
                {{ $slot }}
            </div>
        </main>
    </div>

    <livewire:quick-entry />
    <livewire:transaction-drawer />
    <livewire:category-drawer />

    {{-- Toasts --}}
    <div class="pointer-events-none fixed right-4 bottom-4 z-[60] flex flex-col items-end gap-2" aria-live="polite">
        <template x-for="t in toasts" :key="t.id">
            <div class="pointer-events-auto flex animate-pop items-center gap-2 rounded-lg border border-line-strong bg-raised px-3 py-2 text-[13px] shadow-2xl">
                <span class="size-1.5 rounded-full" :class="t.tone === 'error' ? 'bg-expense' : 'bg-brand'"></span>
                <span x-text="t.message"></span>
            </div>
        </template>
    </div>

    @livewireScriptConfig
</body>
</html>
